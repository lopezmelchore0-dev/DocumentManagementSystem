<?php
// App entry point: opening the site root goes to the login page, which plays the
// start-up splash (includes/splash.php). Signed-in users are sent on to the dashboard by session.php.
header("Location: login.php");
exit();
