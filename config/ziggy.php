<?php

return [

    // Signed out, a page only needs the login routes: the rest of the app's
    // addresses aren't handed to whoever opens the login page.
    'groups' => [
        'guest' => ['login', 'login.store'],
    ],

];
