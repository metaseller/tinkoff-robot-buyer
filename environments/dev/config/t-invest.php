<?php

return [
    'app_name' => 'metaseller.tinkoff-robot-buyer',

    'profiles' => [
        /** Формат */
        '<profile_alias>' => [
            'secret_key' => '<string_t-invest_token>',
            'default_account_id' => '<numeric_t-invest_account_id>',

            'accounts' => [
                '<unique_not_numeric_account_alias_1>' => '<numeric_t-invest_account_id_1>',
                '<unique_not_numeric_account_alias_2>' => '<numeric_t-invest_account_id_2>',
                '<unique_not_numeric_account_alias_3>' => '<numeric_t-invest_account_id_3>',
            ],
        ],

        /** Пример */
        'default' => [
            'secret_key' => 't.K.........Q',
            'default_account_id' => '2........4',

            'accounts' => [
                'account1' => '2........7',
                'account2' => '2........1',
                'account3' => '2........4',
            ],
        ],
    ],
];
