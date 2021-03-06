<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\Role;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\Plugin\PluginInstaller;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\Permission;

trait CustomValueForm
{
    /**
     * Make a form builder.
     * @param $id if edit mode, set model id
     * @return Form
     */
    protected function form($id = null)
    {
        $this->setFormViewInfo(request());

        $classname = $this->getModelNameDV();
        $form = new Form(new $classname);

        //PluginInstaller::pluginPreparing($this->plugins, 'loading');
        // create
        if (!isset($id)) {
            $isButtonCreate = true;
            $listButton = PluginInstaller::pluginPreparingButton($this->plugins, 'form_menubutton_create');
        }
        // edit
        else {
            $isButtonCreate = false;
            $listButton = PluginInstaller::pluginPreparingButton($this->plugins, 'form_menubutton_edit');
        }

        //TODO: escape laravel-admin bug.
        //https://github.com/z-song/laravel-admin/issues/1998
        $form->hidden('laravel_admin_escape');

        // add parent select if this form is 1:n relation
        $relation = CustomRelation::getRelationByChild($this->custom_table, RelationType::ONE_TO_MANY);
        if (isset($relation)) {
            $parent_custom_table = $relation->parent_custom_table;
            $form->hidden('parent_type')->default($parent_custom_table->table_name);

            // set select options
            if ($parent_custom_table->isGetOptions()) {
                $form->select('parent_id', $parent_custom_table->table_view_name)
                ->options(function ($value) use ($parent_custom_table) {
                    return $parent_custom_table->getOptions($value, null, false, true);
                })
                ->required();
            } else {
                $form->select('parent_id', $parent_custom_table->table_view_name)
                ->options(function ($value) use ($parent_custom_table) {
                    return $parent_custom_table->getOptions($value, null, false, true);
                })
                ->ajax($parent_custom_table->getOptionAjaxUrl())
                ->required();
            }
        }

        // loop for custom form blocks
        foreach ($this->custom_form->custom_form_blocks as $custom_form_block) {
            // if available is false, continue
            if (!$custom_form_block->available) {
                continue;
            }
            // when default block, set as normal form columns.
            if ($custom_form_block->form_block_type == FormBlockType::DEFAULT) {
                $form->embeds('value', exmtrans("common.input"), $this->getCustomFormColumns($form, $custom_form_block, $id))
                    ->disableHeader();
            }
            // one_to_many or manytomany
            else {
                list($relation_name, $block_label) = $this->getRelationName($custom_form_block);
                $target_table = $custom_form_block->target_table;
                // 1:n
                if ($custom_form_block->form_block_type == FormBlockType::ONE_TO_MANY) {
                    // get form columns count
                    $form_block_options = array_get($custom_form_block, 'options', []);
                    // if form_block_options.hasmany_type is 1, hasmanytable
                    if (boolval(array_get($form_block_options, 'hasmany_type'))) {
                        $form->hasManyTable(
                            $relation_name,
                            $block_label,
                            function ($form) use ($custom_form_block, $id) {
                                $form->nestedEmbeds('value', $this->custom_form->form_view_name, function (Form\EmbeddedForm $form) use ($custom_form_block, $id) {
                                    $this->setCustomFormColumns($form, $custom_form_block, $id);
                                });
                            }
                        )->setTableWidth(12, 0);
                    }
                    // default,hasmany
                    else {
                        $form->hasMany(
                            $relation_name,
                            $block_label,
                            function ($form) use ($custom_form_block, $id) {
                                $form->nestedEmbeds('value', $this->custom_form->form_view_name, $this->getCustomFormColumns($form, $custom_form_block, $id))
                                ->disableHeader();
                            }
                        );
                    }
                }
                // n:n
                else {
                    $field = new Field\Listbox(
                        CustomRelation::getRelationNameByTables($this->custom_table, $target_table),
                        [$custom_form_block->target_table->table_view_name]
                    );
                    $custom_table = $this->custom_table;
                    $field->options(function ($select) use ($custom_table, $target_table) {
                        return $target_table->getOptions($select, $custom_table);
                    });
                    if (!$target_table->isGetOptions()) {
                        $field->ajax($target_table->getOptionAjaxUrl());
                    }
                    $field->settings(['nonSelectedListLabel' => exmtrans('custom_value.bootstrap_duallistbox_container.nonSelectedListLabel'), 'selectedListLabel' => exmtrans('custom_value.bootstrap_duallistbox_container.selectedListLabel')]);
                    $field->help(exmtrans('custom_value.bootstrap_duallistbox_container.help'));
                    $form->pushField($field);
                }
            }
        }

        $calc_formula_array = [];
        $changedata_array = [];
        $relatedlinkage_array = [];
        $this->setCustomFormEvents($calc_formula_array, $changedata_array, $relatedlinkage_array);

        // add calc_formula_array and changedata_array info
        if (count($calc_formula_array) > 0) {
            $json = json_encode($calc_formula_array);
            $script = <<<EOT
            var json = $json;
            Exment.CommonEvent.setCalcEvent(json);
EOT;
            Admin::script($script);
        }
        if (count($changedata_array) > 0) {
            $json = json_encode($changedata_array);
            $script = <<<EOT
            var json = $json;
            Exment.CommonEvent.setChangedataEvent(json);
EOT;
            Admin::script($script);
        }
        if (count($relatedlinkage_array) > 0) {
            $json = json_encode($relatedlinkage_array);
            $script = <<<EOT
            var json = $json;
            Exment.CommonEvent.setRelatedLinkageEvent(json);
EOT;
            Admin::script($script);
        }

        // add role form
        $this->setRoleForm($form);

        // add form saving and saved event
        $this->manageFormSaving($form);
        $this->manageFormSaved($form);

        $form->disableReset();

        $custom_table = $this->custom_table;
        $custom_form = $this->custom_form;

        $this->manageFormToolButton($form, $id, $custom_table, $custom_form, $isButtonCreate, $listButton);
        return $form;
    }

    /**
     * setRoleForm.
     * if table is user, org, etc...., not set role
     */
    protected function setRoleForm($form)
    {
        // if ignore user and org, return
        if (in_array($this->custom_table->table_name, [SystemTableName::USER, SystemTableName::ORGANIZATION])) {
            return;
        }
        // if table setting is "one_record_flg" (can save only one record), return
        if (boolval(array_get($this->custom_table->options, 'one_record_flg'))) {
            return;
        }
        
        // not contains edit all form
        if (!$this->custom_table->hasPermission(Permission::CUSTOM_VALUE_EDIT_ALL)) {
            return;
        }

        // set addRoleForm(dufinition by trait)
        $this->addRoleForm($form, RoleType::VALUE);
    }

    /**
     * set custom form columns
     */
    protected function setCustomFormColumns($form, $custom_form_block, $id = null)
    {
        $fields = []; // setting fields.
        foreach ($custom_form_block->custom_form_columns as $form_column) {
            if (!isset($id) && $form_column->form_column_type == FormColumnType::SYSTEM) {
                continue;
            }

            $item = $form_column->column_item;
            if (isset($id)) {
                $item->id($id);
            }
            $form->pushField($item->getAdminField($form_column));
        }
    }

    /**
     * set custom form columns
     */
    protected function getCustomFormColumns($form, $custom_form_block, $id = null)
    {
        $closures = [[], []];
        $custom_value = $this->getModelNameDV()::find($id);
        // setting fields.
        foreach ($custom_form_block->custom_form_columns as $form_column) {
            if (!isset($id) && $form_column->form_column_type == FormColumnType::SYSTEM) {
                continue;
            }
            
            $field = $form_column->column_item->setCustomValue($custom_value)->getAdminField($form_column);

            // set $closures using $form_column->column_no
            if (isset($field)) {
                $column_no = array_get($form_column, 'column_no');
                if ($column_no == 2) {
                    $closures[1][] = $field;
                } else {
                    $closures[0][] = $field;
                }
            }
        }

        // if $closures[1] count is 0, return closure function
        if (count($closures[1]) == 0) {
            return function ($form) use ($closures) {
                foreach ($closures[0] as $field) {
                    // push field to form
                    $form->pushField($field);
                }
            };
        }
        return collect($closures)->map(function ($closure) {
            return function ($form) use ($closure) {
                foreach ($closure as $field) {
                    // push field to form
                    $field->setWidth(8, 3);
                    $form->pushField($field);
                }
            };
        })->toArray();
    }

    /**
     * set custom form columns
     */
    protected function setCustomFormEvents(&$calc_formula_array, &$changedata_array, &$relatedlinkage_array)
    {
        foreach ($this->custom_form->custom_form_blocks as $custom_form_block) {
            foreach ($custom_form_block->custom_form_columns as $form_column) {
                if ($form_column->form_column_type != FormColumnType::COLUMN) {
                    continue;
                }
                $column = $form_column->custom_column;
                $form_column_options = $form_column->options;
                $options = $column->options;
                
                // set calc rule for javascript
                if (array_key_value_exists('calc_formula', $options)) {
                    $this->setCalcFormulaArray($column, $options, $calc_formula_array);
                }
                // data changedata
                // if set form_column_options changedata_target_column_id, and changedata_column_id
                if (array_key_value_exists('changedata_target_column_id', $form_column_options) && array_key_value_exists('changedata_column_id', $form_column_options)) {
                    ///// set changedata info
                    $this->setChangeDataArray($column, $form_column_options, $options, $changedata_array);
                }
            }

            // set relatedlinkage_array
            $this->setRelatedLinkageArray($custom_form_block, $relatedlinkage_array);
        }
    }


    protected function manageFormSaving($form)
    {
        // before saving
        $form->saving(function ($form) {
            PluginInstaller::pluginPreparing($this->plugins, 'saving');
        });
    }

    protected function manageFormSaved($form)
    {
        // after saving
        $form->saved(function ($form) {
            $form->model()->setValueAuthoritable();
            PluginInstaller::pluginPreparing($this->plugins, 'saved');

            // if requestsession "file upload uuid"(for set data this value's id and type into files)
            $uuids = System::requestSession(Define::SYSTEM_KEY_SESSION_FILE_UPLOADED_UUID);
            if (isset($uuids)) {
                foreach ($uuids as $uuid) {
                    File::getData(array_get($uuid, 'uuid'))->saveCustomValue($form->model(), array_get($uuid, 'column_name'));
                }
            }

            // if $one_record_flg, redirect
            $one_record_flg = boolval(array_get($this->custom_table->options, 'one_record_flg'));
            if ($one_record_flg) {
                admin_toastr(trans('admin.save_succeeded'));
                return redirect(admin_urls('data', $this->custom_table->table_name));
            }
        });
    }

    protected function manageFormToolButton($form, $id, $custom_table, $custom_form, $isButtonCreate, $listButton)
    {
        $form->tools(function (Form\Tools $tools) use ($form, $id, $custom_table, $custom_form, $isButtonCreate, $listButton) {        // Disable back btn.
            // if one_record_flg, disable list
            if (array_get($custom_table->options, 'one_record_flg')) {
                $tools->disableListButton();
                $tools->disableDelete();
                $tools->disableView();
            }

            // if user only view, disable delete and view
            elseif (!$custom_table->hasPermissionEditData($id)) {
                $tools->disableDelete();
                $tools->disableView();
                disableFormFooter($form);
            }

            // add plugin button
            if ($listButton !== null && count($listButton) > 0) {
                foreach ($listButton as $plugin) {
                    $tools->append(new Tools\PluginMenuButton($plugin, $this->custom_table));
                }
            }
            
            $tools->add((new Tools\GridChangePageMenu('data', $custom_table, false))->render());
        });
    }
    
    /**
     * Create calc formula info.
     */
    protected function setCalcFormulaArray($column, $options, &$calc_formula_array)
    {
        if (is_null($calc_formula_array)) {
            $calc_formula_array = [];
        }
        // get format for calc formula
        $option_calc_formulas = array_get($options, "calc_formula");
        if ($option_calc_formulas == "null") {
            return;
        } //TODO:why???
        if (!is_array($option_calc_formulas) && is_json($option_calc_formulas)) {
            $option_calc_formulas = json_decode($option_calc_formulas, true);
        }

        // keys for calc trigger on display
        $keys = [];
        // loop $option_calc_formulas and get column_name
        foreach ($option_calc_formulas as &$option_calc_formula) {
            if (!in_array(array_get($option_calc_formula, 'type'), ['dynamic', 'select_table'])) {
                continue;
            }
            // set column name
            $formula_column = CustomColumn::getEloquent(array_get($option_calc_formula, 'val'));
            // get column name as key
            $key = $formula_column->column_name ?? null;
            if (!isset($key)) {
                continue;
            }
            $keys[] = $key;
            // set $option_calc_formula val using key
            $option_calc_formula['val'] = $key;

            // if select table, set from value
            if ($option_calc_formula['type'] == 'select_table') {
                $column_from = CustomColumn::getEloquent(array_get($option_calc_formula, 'from'));
                $option_calc_formula['from'] = $column_from->column_name ?? null;
            }
        }

        // loop for $keys and set $calc_formula_array
        foreach ($keys as $key) {
            // if not exists $key in $calc_formula_array, set as array
            if (!array_has($calc_formula_array, $key)) {
                $calc_formula_array[$key] = [];
            }
            // set $calc_formula_array
            $calc_formula_array[$key][] = [
                'options' => $option_calc_formulas,
                'to' => $column->column_name
            ];
        }
    }

    /**
     * set change data array.
     * "change data": When selecting a list, paste the value of that item into another form item.
     */
    protected function setChangeDataArray($column, $form_column_options, $options, &$changedata_array)
    {
        // get this table
        $column_table = $column->custom_table;

        // get getting target model name
        $changedata_target_column_id = array_get($form_column_options, 'changedata_target_column_id');
        $changedata_target_column = CustomColumn::getEloquent($changedata_target_column_id);
        $changedata_target_table = $changedata_target_column->custom_table;
        
        // get table column. It's that when get model data, copied from column
        $changedata_column_id = array_get($form_column_options, 'changedata_column_id');
        $changedata_column = CustomColumn::getEloquent($changedata_column_id);
        $changedata_table = $changedata_column->custom_table;

        // get select target table
        $select_target_table = CustomTable::getEloquent(array_get($changedata_target_column, 'options.select_target_table'));

        // if different $column_table and changedata_target_table, get to_target block name using relation
        if ($column_table->id != $changedata_target_table->id) {
            $to_block_name = CustomRelation::getRelationNameByTables($changedata_target_table, $column_table);
        } else {
            $to_block_name = null;
        }

        // if not exists $changedata_target_column->column_name in $changedata_array
        if (!array_has($changedata_array, $changedata_target_column->column_name)) {
            $changedata_array[$changedata_target_column->column_name] = [];
        }
        if (!array_has($changedata_array[$changedata_target_column->column_name], $select_target_table->table_name)) {
            $changedata_array[$changedata_target_column->column_name][$select_target_table->table_name] = [];
        }
        // push changedata column from and to column name
        $changedata_array[$changedata_target_column->column_name][$select_target_table->table_name][] = [
            'from' => $changedata_column->column_name, // target_table's column
            'to' => $column->column_name, // set data
            'to_block' => is_null($to_block_name) ? null : '.has-many-' . $to_block_name . ',.has-many-table-' . $to_block_name,
            'to_block_form' => is_null($to_block_name) ? null : '.has-many-' . $to_block_name . '-form,.has-many-table-' . $to_block_name.'-form',
        ];
    }
    
    /**
     * set related linkage array.
     * "related linkage": When selecting a value, change the choices of other list. It's for 1:n relation.
     */
    protected function setRelatedLinkageArray($custom_form_block, &$relatedlinkage_array)
    {
        // set target_table columns
        $columns = [];
        // if available is false, continue
        if (!$custom_form_block->available) {
            return;
        }
        foreach ($custom_form_block->custom_form_columns as $form_column) {
            $column = $form_column->custom_column;

            // if column_type is not select_table, continue
            if (array_get($column, 'column_type') != 'select_table') {
                continue;
            }
            // set columns
            $columns[] = $column;
        }

        // re-loop for relation
        foreach ($columns as $column) {
            // get relation
            $relations = CustomRelation::getRelationsByParent(array_get($column, 'options.select_target_table'));
            // if not exists, continue
            if (!$relations) {
                continue;
            }
            foreach ($relations as $relation) {
                // add $relatedlinkage_array if contains
                $child_custom_table_id = array_get($relation, 'child_custom_table_id');
                collect($columns)->filter(function ($c) use ($child_custom_table_id) {
                    return array_get($c, 'options.select_target_table') == $child_custom_table_id;
                })->each(function ($c) use ($column, $relation, &$relatedlinkage_array) {
                    $column_name = array_get($column, 'column_name');
                    // if not exists $column_name in $relatedlinkage_array
                    if (!array_has($relatedlinkage_array, $column_name)) {
                        $relatedlinkage_array[$column_name] = [];
                    }
                    // add array. key is column name.
                    $relatedlinkage_array[$column_name][] = [
                        'url' => admin_urls('webapi', 'data', $relation->parent_custom_table->table_name, 'relatedLinkage'),
                        'expand' => ['child_table_id' => $relation->child_custom_table_id],
                        'to' => array_get($c, 'column_name'),
                    ];
                });
            }
        }
    }
}
