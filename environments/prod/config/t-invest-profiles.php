<?php

return [
    'app_name' => 'metaseller.tinkoff-robot-buyer',

    'profiles' => [
        /** Формат */
        '<profile_alias>' => [
            'secret_key' => '<t-invest-token>',
            'default_account_id' => '<t-invest-account-id>',

            'accounts' => [
                '<unique_account_alias_1>' => '<account_id_1>',
                '<unique_account_alias_2>' => '<account_id_2>',
                '<unique_account_alias_3>' => '<account_id_3>',
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
