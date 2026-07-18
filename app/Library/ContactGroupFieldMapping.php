<?php


    namespace App\Library;


    use App\Helpers\Helper;
    use App\Models\ContactGroups;
    use Exception;
    use Illuminate\Support\Facades\DB;

    class ContactGroupFieldMapping
    {

        public $mapping = [];
        public $list;

        // Use lower-case here
        public $preservedFields = ['tags'];

        private function __construct($mapping, $list)
        {
            $this->mapping = $mapping;
            $this->list    = $list;
        }

        public function generateFieldNameFromId(int $id)
        {
            return "field_{$id}";
        }

        /**
         * @throws Exception
         */
        public static function parse(array $mapping, ContactGroups $list)
        {
            self::validate($mapping, $list);

            return new self($mapping, $list);
        }

        public function getHeaders()
        {
            return array_keys($this->mapping);
        }

        /**
         * @throws Exception
         */
        public static function validate($map, $list)
        {
            // Check if EMAIL (required) is included in the map
            $fieldIds     = array_values($map);
            $phoneFieldId = $list->getPhoneField()->id;

            if ( ! in_array($phoneFieldId, $fieldIds)) {
                throw new Exception(__('locale.filezone.phone_number_column_require'));
            }

            // Check if field id is valid
            foreach ($map as $header => $fieldId) {
                if ( ! $list->contactGroupFields()->where('id', $fieldId)->exists()) {
                    throw new Exception(__('locale.contacts.import_file_field_id_invalid', ['id' => $fieldId, 'header' => $header, 'list' => $list->name]));
                }
            }
        }

        public function updateRecordHeaders($r)
        {
            // IMPORTANT: 'tags' must be lower-case
            // Extract the relevant fields, including preserved fields
            $selectedFields = array_merge($this->getHeaders(), $this->preservedFields);
            $record         = array_only($r, $selectedFields);

            // Change original header to mapped field name
            foreach ($this->mapping as $header => $fieldId) {
                $fieldName          = $this->generateFieldNameFromId($fieldId);
                $record[$fieldName] = $record[$header];
                unset($record[$header]);
            }

            return $record;
        }


        public function createTmpTableFromMapping()
        {
            // create a temporary table containing the input subscribers
            $tmpTable       = Helper::table('__tmp_subscribers');
            $phoneFieldId   = $this->list->getPhoneField()->id;
            $phoneFieldName = $this->generateFieldNameFromId($phoneFieldId);

            // @todo: hard-coded charset and COLLATE
            $tmpFields = array_map(function ($fieldId) {
                $fieldName = $this->generateFieldNameFromId($fieldId);

                return "`{$fieldName}` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
            }, $this->mapping);

            foreach ($this->preservedFields as $field) {
                $tmpFields[] = "`{$field}` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
            }

            $tmpFields = implode(',', $tmpFields);

            // Drop table, create table and create index
            DB::statement("DROP TABLE IF EXISTS {$tmpTable};");
            DB::statement("CREATE TABLE {$tmpTable}({$tmpFields}) ENGINE=InnoDB;");
            DB::statement("CREATE INDEX _index_phone_{$tmpTable} ON {$tmpTable}(`{$phoneFieldName}`);");

            return [$tmpTable, $phoneFieldName];
        }

    }
