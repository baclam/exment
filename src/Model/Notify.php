<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Collection;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Services\MailSender;
use Exceedone\Exment\Services\AuthUserOrgHelper;
use Carbon\Carbon;

class Notify extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $guarded = ['id'];
    protected $casts = ['trigger_settings' => 'json', 'action_settings' => 'json'];
    
    public function custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    public function getTriggerSetting($key, $default = null)
    {
        return $this->getJson('trigger_settings', $key, $default);
    }

    public function getActionSetting($key, $default = null)
    {
        return $this->getJson('action_settings', $key, $default);
    }

    /**
     * notify user
     */
    public function notifyUser()
    {
        list($datalist, $table, $column) = $this->getNotifyTargetDatalist();

        // loop data
        foreach ($datalist as $data) {
            $users = $this->getNotifyTargetUsers($data);
            foreach ($users as $user) {
                $prms = [
                    'user' => $user,
                    'notify' => $this,
                    'target_table' => $table->table_view_name ?? null,
                    'notify_target_column_key' => $column->column_view_name ?? null,
                    'notify_target_column_value' => $data->getValue($column),
                ];

                // send mail
                try {
                    MailSender::make(array_get($this->action_settings, 'mail_template_id'), $user->getValue('email'))
                    ->prms($prms)
                    ->user($user)
                    ->custom_value($data)
                    ->send();
                }
                // throw mailsend Exception
                catch (\Swift_TransportException $ex) {
                    // TODO:loging error
                }
            }
        }
    }
    
    /**
     * notify_create_update_user
     */
    public function notifyCreateUpdateUser($data, $create = true)
    {
        $custom_table = $data->custom_table;
        $mail_send_log_table = CustomTable::getEloquent(SystemTableName::MAIL_SEND_LOG);
        $mail_template = getModelName(SystemTableName::MAIL_TEMPLATE)::where('value->mail_key_name', MailKeyName::DATA_SAVED_NOTIFY)->first();

        // loop data
        $users = $this->getNotifyTargetUsers($data);
        
        foreach ($users as $user) {
            if (!$this->approvalSendUser($mail_template, $custom_table, $data, $user)) {
                continue;
            }

            $prms = [
                'user' => $user,
                'notify' => $this,
                'target_table' => $custom_table->table_view_name ?? null,
                'create_or_update' => $create ? exmtrans('common.created') : exmtrans('common.updated')
            ];

            // send mail
            try {
                MailSender::make($mail_template, $user)
                ->prms($prms)
                ->user($user)
                ->custom_value($data)
                ->send();
            }
            // throw mailsend Exception
            catch (\Swift_TransportException $ex) {
                // show warning message
                admin_warning(exmtrans('error.header'), exmtrans('error.mailsend_failed'));
            }
        }
    }
    
    /**
     * get notify target datalist
     */
    protected function getNotifyTargetDatalist()
    {
        // get target date number.
        $before_after_number = intval(array_get($this->trigger_settings, 'notify_beforeafter'));
        $notify_day = intval(array_get($this->trigger_settings, 'notify_day'));

        // calc target date
        $target_date = Carbon::today()->addDay($before_after_number * $notify_day * -1);
        $target_date_str = $target_date->format('Y-m-d');

        // get target table and column
        $table = $this->custom_table;
        $column = CustomColumn::getEloquent(array_get($this, 'trigger_settings.notify_target_column'));

        // find data. where equal target_date
        $datalist = getModelName($table)
            ::where('value->'.$column->column_name, $target_date_str)
            ->get();

        return [$datalist, $table, $column];
    }
        
    /**
     * get notify target users
     */
    protected function getNotifyTargetUsers($data)
    {
        $notify_action_target = $this->getActionSetting('notify_action_target');
        if (!isset($notify_action_target)) {
            return [];
        }

        if (!is_array($notify_action_target)) {
            $notify_action_target = [$notify_action_target];
        }

        // loop
        $users = collect([]);
        $ids = [];
        foreach ($notify_action_target as $notify_act) {

            // if has_roles, return has permission users
            if ($notify_act == NotifyActionTarget::HAS_ROLES) {
                $users_inner = AuthUserOrgHelper::getAllRoleUserQuery($data)->get();
            } else {
                $users_inner = $data->getValue($notify_act);
                if (is_null($users_inner)) {
                    continue;
                }
                if (!($users_inner instanceof Collection)) {
                    $users_inner = collect([$users_inner]);
                }
            }

            foreach ($users_inner as $u) {
                if (in_array($u->id, $ids)) {
                    continue;
                }
                $ids[] = $u->id;
                $users->push($u);
            }
        }

        return $users;
    }

    /**
     *
     */
    protected function approvalSendUser($mail_template, $custom_table, $data, $user)
    {
        $mail_send_log_table = CustomTable::getEloquent(SystemTableName::MAIL_SEND_LOG);

        // if already send notify in 1 minutes, continue.
        $index_user = CustomColumn::getEloquent('user', $mail_send_log_table)->getIndexColumnName();
        $index_mail_template = CustomColumn::getEloquent('mail_template', $mail_send_log_table)->getIndexColumnName();
        $mail_send_histories = getModelName(SystemTableName::MAIL_SEND_LOG)
            ::where($index_user, $user->id)
            ->where($index_mail_template, $mail_template->id)
            ->where('parent_id', $data->id)
            ->where('parent_type', $custom_table->table_name)
            ->get()
        ;
        foreach ($mail_send_histories as $mail_send_log) {
            // If user were sending within 5 minutes, false
            $skip_mitutes = config('exment.notify_saved_skip_minutes', 5);
            $send_datetime = (new Carbon($mail_send_log->getValue('send_datetime')))
                ->addMinutes($skip_mitutes);
            $now = Carbon::now();
            if ($send_datetime->gt($now)) {
                return false;
            }
        }

        return true;
    }
}
