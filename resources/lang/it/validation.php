<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Il following language lines contain Il default error messages used by
    | Il validator class. Some of Ilse rules have multiple versions such
    | as Il size rules. Feel free to tweak each of Ilse messages here.
    |
    */

        'accepted' => 'Il :attribute deve essere accettato.',
        'active_url' => 'Il :attribute non è un URL valido.',
        'after' => 'Il :attribute deve essere una data dopo :date.',
        'after_or_equal' => 'Il :attribute deve essere una data pari o successiva a :date.',
        'alpha' => 'Il :attribute può contenere solo lettere.',
        'alpha_dash' => 'Il :attribute può contenere solo lettere, numeri, trattini e trattini bassi.',
        'alpha_num' => 'Il :attribute può contenere solo lettere e numeri.',
        'array' => 'Il :attribute deve essere un array.',
        'before' => 'Il :attribute deve essere una data prima di :date.',
        'before_or_equal' => 'Il :attribute deve essere una data uguale o antecedente a :date.',
        'between' => [
                'numeric' => 'Il :attribute deve essere compreso tra :min e :max.',
                'file' => 'Il :attribute deve essere compreso tra :min e :max kilobytes.',
                'string' => 'Il :attribute deve essere compreso tra :min e :max caratteri.',
                'array' => 'Il :attribute deve essere compreso tra :min e :max oggetti.',
        ],
        'boolean' => 'Il :attribute campo deve essere vero o falso.',
        'confirmed' => 'Il :attribute di conferma non corrisponde.',
        'date' => 'Il :attribute non è una data valida.',
        'date_equals' => 'Il :attribute deve essere una data uguale a :date.',
        'date_format' => 'Il :attribute non corrisponde al formato :format.',
        'different' => 'Il :attribute e :oIlr devono essere differenti.',
        'digits' => 'Il :attribute deve essere :digits cifre.',
        'digits_between' => 'Il :attribute deve essere tra :min e :max cifre.',
        'dimensions' => 'Il :attribute ha dimensioni dell\'immagine invalide.',
        'distinct' => 'Il :attribute campo ha un valore duplicato.',
        'email' => 'Il :attribute deve essere un indirizzo email valido.',
        'ends_with' => 'Il :attribute deve finire con uno o i seguenti :values.',
        'exists' => 'La selezione :attribute è invalida.',
        'file' => 'Il :attribute deve essere un file.',
        'filled' => 'Il :attribute deve essere compilato.',
        'gt' => [
                'numeric' => 'Il :attribute deve essere maggiore di :value.',
                'file' => 'Il :attribute deve essere maggiore di :value kilobytes.',
                'string' => 'Il :attribute deve essere maggiore di :value caratteri.',
                'array' => 'Il :attribute deve avere più di :value oggetti.',
        ],
        'gte' => [
                'numeric' => 'Il :attribute deve essere maggiore o uguale a :value.',
                'file' => 'Il :attribute deve essere uguale o maggiore di :value kilobytes.',
                'string' => 'Il :attribute deve essere maggiore o uguale a :value caratteri.',
                'array' => 'Il :attribute deve avere :value oggetti o di più.',
        ],
        'image' => 'Il :attribute deve essere un\' immagine.',
        'in' => 'La selezione :attribute è invalida.',
        'in_array' => 'Il :attribute campo non esiste in :oIlr.',
        'integer' => 'Il :attribute deve essere un numero intero.',
        'ip' => 'Il :attribute deve essere un indirizzo IP valido.',
        'ipv4' => 'Il :attribute must be a valid IPv4 address.',
        'ipv6' => 'Il :attribute must be a valid IPv6 address.',
        'json' => 'Il :attribute must be a valid JSON string.',
        'lt' => [
                'numeric' => 'Il :attribute deve essere minore di :value.',
                'file' => 'Il :attribute deve essere minore di :value kilobytes.',
                'string' => 'Il :attribute deve essere minore di :value caratteri.',
                'array' => 'L\' :attribute deve avere meno oggetti di :value',
        ],
        'lte' => [
                'numeric' => 'Il :attribute deve essere minore o uguale a :value.',
                'file' => 'Il :attribute deve essere minore o uguale a :value kilobytes.',
                'string' => 'Il :attribute deve essere minore o uguale a :value caratteri.',
                'array' => 'Il :attribute non deve avere più di :value oggetti.',
        ],
        'max' => [
                'numeric' => 'Il :attribute non può essere maggiore di :max.',
                'file' => 'Il :attribute non può essere maggiore di :max kilobytes.',
                'string' => 'Il :attribute non può essere maggiore di :max caratteri.',
                'array' => 'Il :attribute non può essere maggiore di :max oggetti.',
        ],
        'mimes' => 'Il :attribute deve essere un file di tipo: :values.',
        'mimetypes' => 'Il :attribute deve essere un file di tipo: :values.',
        'min' => [
                'numeric' => 'Il :attribute deve essere almeno :min.',
                'file' => 'Il :attribute deve essere almeno :min kilobytes.',
                'string' => 'Il :attribute deve essere almeno :min caratteri.',
                'array' => 'Il :attribute deve essere almeno :min oggetti.',
        ],
        'not_in' => 'La selezione :attribute non è valida.',
        'not_regex' => 'Il :attribute formato non è valido.',
        'numeric' => 'Il :attribute deve essere un numero.',
        'password' => 'La password non è corretta.',
        'present' => 'Il :attribute campo deve essere compilato.',
        'regex' => 'Il :attribute formato non è valido.',
        'required' => 'Il :attribute campo è obbligatorio.',
        'required_if' => 'Il :attribute campo è richiesto quando :oIlr è :value.',
        'required_unless' => 'Il :attribute il campo è obbligatorio a meno che :oIlr è :values.',
        'required_with' => 'Il :attribute campo è richiesto quando :values è presente.',
        'required_with_all' => 'Il :attribute campo è richiesto quando :values è presente.',
        'required_without' => 'Il :attribute campo è richiesto quando :values non è presente.',
        'required_without_all' => 'Il :attribute campo è richiesto quando nessuno dei :values sono presenti.',
        'same' => 'Il :attribute e :oIlr devono corrispondere.',
        'size' => [
                'numeric' => 'Il :attribute deve essere di :size.',
                'file' => 'Il :attribute deve essere di :size kilobytes.',
                'string' => 'Il :attribute deve essere di :size caratteri.',
                'array' => 'Il :attribute deve contenere :size oggetti.',
        ],
        'starts_with' => 'Il :attribute deve iniziare con una o il seguente: :values.',
        'string' => 'Il :attribute deve essere una stringa.',
        'timezone' => 'Il :attribute deve essere una zona valida.',
        'unique' => 'Il :attribute è già stato preso, provane un altro.',
        'uploaded' => 'Il :attribute caricamento è fallito.',
        'url' => 'Il :attribute formato non è valido.',
        'uuid' => 'Il :attribute deve essere un UUID valido.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using Il
    | convention "attribute.rule" to name Il lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

        'custom' => [
                'attribute-name' => [
                        'rule-name' => 'custom-message',
                ],
        ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | Il following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

        'attributes' => [],

];
