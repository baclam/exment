<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Relations\Relation;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\SystemTableName;

class Morph
{
    public function handle(Request $request, \Closure $next)
    {
        static::defineMorphMap();
        return $next($request);
    }

    /**
     * define morph map. this called from command.
     *
     */
    public static function defineMorphMap()
    {
        // morphMap
        try {
            if (hasTable(SystemTableName::CUSTOM_TABLE)) {
                $table_names = \DB::table(SystemTableName::CUSTOM_TABLE)
                    ->whereNull('deleted_at')
                    ->get(['table_name'])
                    ->pluck('table_name');
                    
                $morphMaps = [
                    "roles" => Model\Role::class,
                    "table" => Model\CustomTable::class
                ];
                foreach ($table_names as $table_name) {
                    // morphmap
                    $morphMaps[$table_name] = ltrim(getModelName($table_name, true), "\\");

                    // Define Modelname
                    getModelName($table_name);
                }
                Relation::morphMap($morphMaps);
            }
        } catch (\Exception $ex) {
            logger($ex);
        }
    }
}
