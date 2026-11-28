<?php
$tmp = $_FILES['doc']['tmp_name'];
move_uploaded_file($tmp, '/var/www/uploads/' . $newname);
