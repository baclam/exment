<?php

namespace Exceedone\Exment\Model;

use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Enums\CopyColumnType;
use Exceedone\Exment\Enums\RelationType;

class CustomCopy extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\UseRequestSessionTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonTrait;
    use Traits\TemplateTrait;
    
    protected $casts = ['options' => 'json'];

    public static $templateItems = [
        'excepts' => ['from_custom_table', 'to_custom_table', 'target_copy_name'],
        'langs' => [
            'keys' => ['suuid'],
            'values' => ['options.label'],
        ],
        'uniqueKeys' => ['suuid'],
        'uniqueKeyReplaces' => [
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'from_custom_table_id',
                        'replacedName' => [
                            'table_name' => 'from_custom_table_name',
                        ]
                    ],
                    [
                        'replacingName' => 'to_custom_table_id',
                        'replacedName' => [
                            'table_name' => 'to_custom_table_name',
                        ]
                    ],
                ],
                'uniqueKeyClassName' => CustomTable::class,
            ],
        ],
        'children' =>[
            'custom_copy_columns' => CustomCopyColumn::class,
            'custom_copy_input_columns' => CustomCopyColumn::class,
        ],
    ];
    
    public function from_custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'from_custom_table_id');
    }

    public function to_custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'to_custom_table_id');
    }

    public function custom_copy_columns()
    {
        return $this->hasMany(CustomCopyColumn::class, 'custom_copy_id')
        ->where('copy_column_type', CopyColumnType::DEFAULT);
    }

    public function custom_copy_input_columns()
    {
        return $this->hasMany(CustomCopyColumn::class, 'custom_copy_id')
        ->where('copy_column_type', CopyColumnType::INPUT);
    }

    public function getOption($key, $default = null)
    {
        return $this->getJson('options', $key, $default);
    }
    public function setOption($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('options', $key, $val, $forgetIfNull);
    }
    public function forgetOption($key)
    {
        return $this->forgetJson('options', $key);
    }
    public function clearOption()
    {
        return $this->clearJson('options');
    }
    
    /**
     * execute data copy
     */
    public function execute($from_custom_value, $request = null)
    {
        $to_custom_value = null;
        DB::transaction(function () use (&$to_custom_value, $from_custom_value, $request) {
            $to_custom_value = static::saveCopyModel(
                $this->custom_copy_columns,
                $this->custom_copy_input_columns,
                $this->to_custom_table,
                $from_custom_value,
                $request
            );

            // Copy children values --------------------------------------------------
            // get from and to_table_relations
            $from_relations = $this->from_custom_table->custom_relations;
            $to_relations = $this->to_custom_table->custom_relations;
            if (isset($from_relations) && isset($to_relations)) {
                foreach ($from_relations as $from_relation) {
                    // get from-children values
                    $from_child_custom_values = $from_custom_value->getChildrenValues($from_relation->child_custom_table) ?? [];
                    foreach ($to_relations as $to_relation) {
                        // if not match relation_type, continue
                        if ($from_relation->relation_type != $to_relation->relation_type) {
                            continue;
                        }

                        ////// relation is 1:n
                        if ($from_relation->relation_type == RelationType::ONE_TO_MANY) {
                            // get child copy object. from and to - child table
                            $child_copy = static::where('from_custom_table_id', $from_relation->child_custom_table_id)
                                ->where('to_custom_table_id', $to_relation->child_custom_table_id)
                                ->first();
                            if (!isset($child_copy)) {
                                continue;
                            }
                            // loop children values
                            foreach ($from_child_custom_values as $from_child_custom_value) {
                                // update parent_id to $to_custom_value->id
                                $from_child_custom_value->parent_id = $to_custom_value->id;
                                $from_child_custom_value->parent_type = $to_relation->parent_custom_table->table_name;
                                // execute copy
                                static::saveCopyModel(
                                    $child_copy->custom_copy_columns,
                                    $child_copy->custom_copy_input_columns,
                                    $child_copy->to_custom_table,
                                    $from_child_custom_value
                                );
                            }
                        }
                        ///// n:n
                        else {
                            // if not match child_custom_table_id, continue
                            if ($from_relation->child_custom_table_id != $to_relation->child_custom_table_id) {
                                continue;
                            }
                            // insert new pivot table value
                            $pivot_name = $relation->getRelationName();
                            // insert value. child_id is save value
                            foreach ($from_child_custom_values as $from_child_custom_value) {
                                DB::table($pivot_name)->insert([
                                    'parent_id' => $to_custom_value->id,
                                    'child_id' => $from_child_custom_value->id,
                                ]);
                            }
                        }
                    }
                }
            }

            return true;
        });
        
        return [
            'result'  => true,
            'toastr' => sprintf(exmtrans('common.message.success_execute')),
            // set redirect url
            'redirect' => admin_urls('data', $this->to_custom_table->table_name, $to_custom_value->id)
        ];
    }

    protected static function saveCopyModel($custom_copy_columns, $custom_copy_input_columns, $to_custom_table, $from_custom_value, $request = null)
    {
        // get to_custom_value model
        $to_modelname = getModelName($to_custom_table);
        $to_custom_value = new $to_modelname;

        // set system column
        $to_custom_value->parent_id = $from_custom_value->parent_id;
        $to_custom_value->parent_type = $from_custom_value->parent_type;

        // loop for custom_copy_columns
        foreach ($custom_copy_columns as $custom_copy_column) {
            ///// get from_custom_value
            // check number
            if (is_numeric($custom_copy_column->from_column_target_id)) {
                // get column
                $from_custom_column = $custom_copy_column->from_custom_column;
                // get value. (NOT use getValue function because don't want convert value. get $custom_value->value['column'] value.)
                $val = array_get($from_custom_value, "value.{$from_custom_column->column_name}");
            } else {
                $val = $from_custom_value->{$custom_copy_column->from_column_target_id};
            }

            ///// get tom_custom_value
            // check number
            if (is_numeric($custom_copy_column->to_column_target_id)) {
                $to_custom_column = $custom_copy_column->to_custom_column;
                $to_custom_value->setValue($to_custom_column->column_name, $val);
            } else {
                $to_custom_value->{$custom_copy_column->to_column_target_id} = $val;
            }
        }

        // has request, set value from input
        if (isset($request)) {
            foreach ($custom_copy_input_columns as $custom_copy_input_column) {
                $custom_column = $custom_copy_input_column->to_custom_column;
                // get input value
                $val = $request->input($custom_column->column_name ?? null);
                if (isset($val)) {
                    $to_custom_value->setValue($custom_column->column_name, $val);
                }
            }
        }
        // save
        $to_custom_value->saveOrFail();
        return $to_custom_value;
    }
    
    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
    }
        
    public function deletingChildren()
    {
        $this->custom_copy_columns()->delete();
        $this->custom_copy_input_columns()->delete();
    }

    protected static function boot()
    {
        parent::boot();
        
        static::deleting(function ($model) {
            $model->deletingChildren();
        });
    }
}
