<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Which Pick a Winner screens hosts see
    |--------------------------------------------------------------------------
    |
    | The redesigned draw has to be approved before hosts use it on the night.
    | Until then a site shows the screens it always had (Pages/Legacy), and only
    | a site with PICK_A_WINNER_NEW_UI=true in its .env shows the new ones. The
    | rest of the app is not affected by this.
    |
    | Off by default, so a site that has never heard of this setting keeps the
    | old screens.
    |
    */

    'new_ui' => (bool) env('PICK_A_WINNER_NEW_UI', false),

];
