<?php

namespace App\Library\Traits;

use App\Helpers\Helper;
use Exception;
use Illuminate\Support\Facades\DB;

trait QueryHelper
{
    public function scopePerPage($query, $size, $callback)
    {
        $pages = (int) ceil($query->count() / $size);
        for ($i = 0; $i < $pages; $i += 1) {
            $offset = $size * $i;
            $callback($query->limit($size)->offset($offset));
        }
    }

    /**
     * Creates a temporary table from an array of data.
     *
     * @param  string  $tableName The name of the temporary table.
     * @param  array  $data An array of hash data.
     * @param  array  $fields An array of fields to be used in the table.
     * @param  callable|null  $callback A callback function to handle the table.
     * @return void
     *
     * @throws Exception If an error occurs during the process.
     */
    public function createTemporaryTableFromArray(string $tableName, array $data, array $fields, callable $callback = null)
    {
        // Note: data must be an array of hash
        // Note: fields format looks like this:

        try {
            DB::beginTransaction();

            $table = Helper::table($tableName); // with prefix
            $fieldsSql = implode(',', $fields);

            DB::statement("DROP TABLE IF EXISTS {$table};");
            DB::statement("CREATE TABLE {$table}({$fieldsSql});");

            // Actually insert data
            DB::table($tableName)->insert($data);

            // Pass to the controller for handling
            if (! is_null($callback)) {
                $callback($tableName); // Note: it is without prefix
            }

            // Cleanup
            DB::statement("DROP TABLE IF EXISTS {$table};");

            // It is all done
            DB::commit();
        } catch (Exception $e) {
            // finish the transaction
            DB::rollBack();
            throw $e;
        }
    }
}
