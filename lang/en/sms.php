<?php

/*
| Texts about signing in and staff accounts (ADR 0010, ADR 0019). They name
| the app and the gym, nothing personal. Amharic goes in lang/am/sms.php,
| with the same keys and placeholders (ADR 0034).
*/

return [

    'sign_in_code' => 'Your :app sign-in code is :code. It expires in :minutes minutes. Don\'t share it with anyone.',

    'password_invite' => ':gym added you as staff on :app. Set your password within 7 days: :url',

    'password_reset' => 'Reset your :app staff password within 60 minutes: :url If you didn\'t ask for this, ignore it.',

    'staff_added' => ':gym added you as staff on :app. Sign in at :url with your phone and password.',

];
