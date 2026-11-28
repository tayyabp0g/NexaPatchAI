<h1>Hello <?php echo $_GET['name']; ?></h1>
<p><?= $_POST['comment'] ?></p>
<form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="get"></form>
<?php
print "You searched for " . $_REQUEST['q'];
echo htmlspecialchars($_GET['safe'], ENT_QUOTES, 'UTF-8');
echo intval($_GET['page']);
