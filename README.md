# atb_mysqli
 
[mysqli](http://us3.php.net/manual/en/book.mysqli.php) extended with a few useful methods. Created while updating the [Online Course Reserves Application](https://library.brown.edu/DigitalTechnologies/category/ocra/) at [Brown University Library](http://library.brown.edu) to PHP 5.

## Usage Examples

#### Open a database instance

	$db = new atb_mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

or 

	$db = atb_mysqli::get();

#### Get one line from the `widgets` table

	$widg = $db->getWidgets('id', $widg_id);

This will return the row where `id` equals `$widg_id`, as an associative array. `LIMIT 1` will automatically be added to the end of the query.

You can also use an array to give multiple constraints. This will only return widget `widget_id` if its `owner` is 5:

	$widg = $db->getWidgets(array('id'=>$widg_id, 'owner'=>5));

#### Get all widgets matching a constraint

	$widgs = $db->getAllWidgets('owner', 5);

Again, you can use an array here:

	$widgs = $db->getAllWidgets(array('owner'=>5, 'size'=>'XL'));

#### Get one result row for a query, as an associative array.

	$qry = 'SELECT * FROM sprockets JOIN widgets ON widget_id=widgets.id WHERE sprockets.id=44';
	$widg = $db->getRow($qry);

You can provide multiple arguments here. They'll be run through [`sprintf()`](http://php.net/manual/en/function.sprintf.php) to generate the query that's sent to the database.

	$qry = 'SELECT * FROM sprockets JOIN widgets ON widget_id=widgets.id WHERE sprockets.id=%d';
	$sprock = $db->getRow($qry, $sprockid);

#### Get all results for a query as an array of associative arrays.

	$qry = 'SELECT * FROM sprockets JOIN widgets ON widget_id=widgets.id WHERE size="%s"';
	$sprocks = $db->getAll($qry, $size);

#### Get one value from the database, as a scalar variable.

	$db->getValue("SELECT size FROM widgets WHERE id=5");
	$db->getValue("SELECT COUNT(*) FROM sprockets WHERE widget_id=5");