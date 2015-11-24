<?php

/**
 * @todo Write a useful docblock here.
 * @todo use Prepared Statements internally where possible.
 */
class atb_mysqli extends mysqli {
    private $memo = array();
    private $last_query = null;
    /**
     * Default method call handler
     *
     * Possible methods:
     *  - getAll<table_name>($column, $value):
     *      "SELECT * FROM <table_name> WHERE $column = $value"
     *      (if $value is an array):
     *      "SELECT * FROM <table_name> WHERE $row IN ($value)"
     *  - getAll<tableName>($arr):
     *      Parses $arr into $key $value pairs:
     *      "...WHERE $key1 = $value1 AND $key2 = $value2 ..."
     *  - get<table_name>($column, $value), get<table_name>($arr):
     *      Like getAll... but returns a single row.
     *
     * @todo Refactor. Creating the WHERE clause shouldn't happen here.
     */
    public function __call($name, $args) {
        //FALSE == 0, but FALSE !== 0.
        if ( strpos(strtolower($name), 'getall') === 0 && !$args ) {
            $table = strtolower(substr($name, 6));
            $arglist = array('SELECT * FROM %s', $table);
            return ( $outp = call_user_func_array(array($this, 'getAll'), $arglist) ) ?
                    $outp : false;
        } elseif ( strpos(strtolower($name), 'getall') === 0 ) {
            $table = strtolower(substr($name, 6));

            if ( !is_array($args[0]) ) {
                if ( is_array($args[1]) ) {
                    //$args[0] IN $args[1]
                    $in = "'".implode("','", $args[1])."'";
                    $where = '`'.$args[0]."` IN($in)";
                } else {
                    $where = '`'.$args[0].'` = "'.$args[1].'"';
                }
                $arglist = array('SELECT * FROM `%s` WHERE %s', $table, $where);
                $outp = call_user_func_array(array($this, 'getAll'), $arglist);
            } else {
                //$args[0] should be an associative array.
                $where = $args[0];
                array_walk($where, function(&$v, $k) {
                    //TODO: should be able to handle dates?
                    if ( is_numeric($v) && (strpos($v, '0') !== 0) ) $v = "$k = $v";
                    elseif ( is_array($v) ) {
                        $in = "'".implode("','", $v)."'";
                        $v = "$k IN($in)";
                    } else $v = "$k = '$v'";
                });
                $where = implode(' AND ', $where);
                $outp = $this->getAll('SELECT * FROM %s WHERE %s', $table, $where);
            }

            if ( $outp ) return $outp;
            else return false;
        } elseif ( strpos($name, 'get') === 0 ) {
            $table = strtolower(substr($name, 3));
            if ( !is_array($args[0]) ) {
                array_unshift($args, $table);
                array_unshift($args, 'SELECT * FROM `%s` WHERE `%s` = "%s"');
                $outp = call_user_func_array(array($this, 'getRow'), $args);
            } else {
                $where = $args[0];
                array_walk($where, function(&$v, $k) {
                    //TODO: should be able to handle dates?
                    if ( is_numeric($v) && (strpos($v, '0') !== 0) ) $v = "$k = $v";
                    else $v = "$k = '$v'";
                });
                $where = implode(' AND ', $where);

                $outp = $this->getRow('SELECT * FROM %s WHERE %s', $table, $where);
            }

            if ( $outp ) return $outp;
            return false;
        } else {
            throw new BadMethodCallException("$name: No such method found.");
        }
    }
    
    /**
     * Returns the value of a single field.
     *
     * Needs at least one parameter, as for getResults().
     *
     * @return mixed
     * $todo Test.
     */
    public function getValue() {
        $args = func_get_args();
        $row = call_user_func_array(array($this, 'getRow'), $args);
        
        return $row ? current($row) : false;
    }

    /**
     * Returns a mysqli_result for a query
     *
     * This, Row, and getAll need at least one parameter, an SQL query;
     * if more than 1 argument is given, we'll merge them into a query using:
     * sprintf($arg1, $arg2, ...)
     *
     * Will throw an exception if the query returns an error.
     *
     * @return atb_mysqli_result The result of this query.
     */
    public function getResults() {
        if ( !func_num_args() )
            throw new BadMethodCallException("atb_mysqli::getResults requires at least one parameter.");
        elseif ( func_num_args() > 1 ) {
            $args = func_get_args();
            $query = call_user_func_array('sprintf', $args);
        } else { $query = func_get_arg(0); }
        
        $rs = $this->query($query);

        if ( $this->errno ) {
            throw new Exception("Database error: ".$this->error.'; Query: "'.$query.'"');
        } else {
            return $rs;
        }
    }

    /**
     * Executes a query using mysqli::query() and returns an atb_mysqli_result.
     *
     * atb_mysqli_result has a fetch_all method that should work even if mysqlnd isn't available
     * on your system. This also stores the query in a $last_query property for debugging purposes.
     */
    public function query($query, $resultmode = MYSQLI_STORE_RESULT) {
        $this->last_query = $query;
        
        $rs = parent::query($query, $resultmode);
        if ( "object" == gettype($rs) && "mysqli_result" == get_class($rs) ) {
            return new atb_mysqli_result($rs);
        } else return $rs;
    }

    /**
     * Returns a single row from a databse.
     *
     * See the documentation for getResults for details.
     *
     * @return array The result of the query.
     *
     * @todo Accept the same constants as mysqli::fetch_array().
     */
    public function getRow() {
        if ( !func_num_args() )
            throw new BadMethodCallException("atb_mysqli::getRow requires at least one parameter.");
        $args = func_get_args();
        $args[0] = "{$args[0]} LIMIT 1";

        $rs = call_user_func_array(array($this, 'getResults'), $args);

        if ($rs->num_rows == 1) {
            return $rs->fetch_assoc();
        }
        return false;
    }

    /**
     * Returns all results for a query.
     *
     * See the documentation for getResults for details.
     *
     * @return array The result of the query as an array of associative arrays.
     *
     * @todo Accept the same constants as mysqli::fetch_array().
     * @todo Accept a count argument.
     */
    public function getAll() {
        if ( !func_num_args() )
            throw new BadMethodCallException("atb_mysqli::getAll requires at least one parameter.");

        $rs = call_user_func_array(array($this, 'getResults'), func_get_args());

        if ($rs->num_rows) {
            return $rs->fetch_all();
        }
        return false;
    }

    /**
     * Utility function for insert() and replace().
     *
     * @todo handle dates and nulls
     * @return bool|int On a successful INSERT, the primary key of the new record. otherwise, true for success, false for failure.
     */
    protected function input($action, $table, Array $data) { 
        $cols = '`'.implode('`, `', array_keys($data)).'`';
        
        foreach ( $data as $k => $v ) {
            //TODO: gettype is wrong here.
            switch ( gettype($v) ) {
                case 'boolean':
                    //Assuming this is an ENUM('y', 'n')
                    $data[$k] = $v ? "'y'" : "'n'";
                    break;
                case 'integer':
                case 'double':
                    break;
                case 'array': //Assuming this is a SET.
                    $data[$k] = "'".implode(",", $v)."'";
                    break;
                default:
                    $data[$k] = "'".$this->real_escape_string($v)."'";
                    break;
            }
        }

        $vals = implode(', ', $data);
        $qry = '%s INTO %s (%s) VALUES (%s)';
        $qry = sprintf($qry, strtoupper($action), $table, $cols, $vals);
        if ( $outp = $this->query($qry) && 'insert' == $action ) return $this->insert_id;
        return $outp;
    }

    /**
     * INSERT $data into $table.
     *
     * @param string $table The name of the table to write to.
     * @param array $data An associative array of 'column'=>'value' pairs to write to $table.
     * @return bool|int The primary key of the new record or false.
     */
    public function insert($table, Array $data) {
        $outp = $this->input(__FUNCTION__, $table, $data);
        return $outp;
    }

    /**
     * REPLACE $data into $table.
     *
     * @param string $table The name of the table to write to.
     * @param array $data An associative array of 'column'=>'value' pairs to write to $table.
     * @return bool Was the operation successful?
     */
    public function replace($table, Array $data) {
        return $this->input(__FUNCTION__, $table, $data);
    }

    /**
     * UPDATE data in a table.
     *
     * @todo handle dates.
     * @return int The number of affected rows.
     */
    public function update($table, Array $data, Array $where) {
        //TODO: Move this into its own function.
        $set = array();
        foreach ( $data as $k => $v ) {
            //TODO: gettype is wrong here.
            switch ( gettype($v) ) {
                case 'boolean':
                    //Assuming this is an ENUM('y', 'n')
                    $set[] = "`$k` = ".($v ? "'y'" : "'n'");
                    break;
                case 'integer':
                case 'double':
                    $set[] = "`$k` = $v";
                    break;
                case 'array': //Assuming this is a SET.
                    $set[] = "`$k` = '".implode(",", $v)."'";
                    break;
                default:
                    $set[] = "`$k` = '".$this->real_escape_string($v)."'";
                    break;
            }
        }
        $set = implode(', ', $set);
        array_walk($where, function(&$v, $k, $db) {
            //Retain leading zeroes.
            if ( is_numeric($v) && (strpos($v, '0') !== 0) ) $v = "$k = $v";
            else $v = "$k = '{$db->real_escape_string($v)}'";
        }, $this);
        $where = implode(' AND ', $where);
        $query = 'UPDATE %s SET %s WHERE %s';
        $query = sprintf($query, $table, $set, $where);
        //die($query);
        $rs = $this->query($query);
        
        if ( $this->errno ) {
            throw new Exception("Database error: ".$this->error.'; Query: "'.$query.'"');
        } else {
            return $this->affected_rows;
        }
    }
    
    /**
     * DELETE data from a table.
     *
     * 
     */
    public function delete($table, Array $where) {
        $query = sprintf('DELETE FROM %s %s', $table, $this->_whereClause($where));
        $this->query($query);
        
        if ( $this->errno ) {
            throw new Exception("Database error: ".$this->error.'; Query: "'.$query.'"');
        } else {
            return $this->affected_rows;
        }
    }
    
    /**
     * Given an array of $column => $value pairs, makes a WHERE clause.
     */
    private function _whereClause(Array $where) {
        array_walk($where, function(&$v, $k) {
            if ( is_numeric($v) && (strpos($v, '0') !== 0) ) $v = "$k = $v";
            elseif ( is_array($v) ) {
                $in = "'".implode("','", $v)."'";
                $v = "$k IN($in)";
            } 
            else $v = "$k = '$v'";
        });
        return 'WHERE '.implode(' AND ', $where);
    }
    
    public function __get($name) {
        if ( 'last_query' == $name ) return $this->$name;
    }
    
    /*****************************************************************************************
     * Table descriptions
     *****************************************************************************************/
    
    /**
     * DESCRIBEs a table.
     *
     * @return atb_mysqli_result
     */
    public function describe($table, $database = false) {
        $table = $database ? "$database.$table" : $table;
        
        if ( !array_key_exists("description_$table", $this->memo) ) {
            $qry = "DESCRIBE $table";
            $this->memo["description_$table"] = $this->query($qry);
        }
        
        if ($this->memo["description_$table"]) $this->memo["description_$table"]->data_seek(0);
        return $this->memo["description_$table"];
    }
    
    /**
     * Return a list of fields in a table.
     *
     * @return Array.
     */
    public function listFields($table, $database = false) {
        $rs = $this->describe($table, $database);
        
        $outp = array();
        while ( $rs && $row = $rs->fetch_assoc() ) {
            $outp[] = $row['Field'];
        }
        return $outp;
    }
    
    /**
     * Get a list of options for an ENUM or SET field.
     *
     * $return Array.
     */
    public function getOptions($table, $field, $database=false) {
        $database = $database ? "`$database`." : '';
        $type = $this->getAll('SHOW COLUMNS IN %s%s WHERE `Field` = "%s"', $database, $table, $field);
        if ( !$type ) throw new Exception('atb_mysqli::getOptions database error. Query: '.$this->last_query);
        
        $type = $type[0]['Type'];
        if ( !preg_match('/(?P<type>set|enum)\((?P<opts>.*)\)/i', $type, $m) )
            throw new Exception('atb_mysqli::getOptions failed to parse options.');
        
        $opts = str_getcsv($m['opts'], ',', "'");
        return $opts;
    }
    
    /*****************************************************************************************
     * Prepared statement management
     *****************************************************************************************/
    /**
    * From http://www.php.net/manual/en/mysqli-stmt.fetch.php
    */
    public static function stmt_bind_assoc(&$stmt, Array &$out = array()) {
        $data = $stmt->result_metadata();
        $fields = array();
        $out = array();
    
        $count = 0;
    
        while($field = mysqli_fetch_field($data)) {
            $fields[$count] = &$out[$field->name];
            $count++;
        }
        call_user_func_array(array($stmt, 'bind_result'), $fields);
        return $out;
    }
    
    private static $single = null;
    /**
     * Singleton.
     */
    public static function get() {
        if ( is_null(self::$single) ) {
            $single = new static(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        }
    }
}

/**
 * Simple wrapper for mysqli_result. Has a fetch_all method that works without mysqlnd.
 */
class atb_mysqli_result {
    private $rs;

    public function __construct(mysqli_result $rs) {
        $this->rs = $rs;
    }

    public function __call($name, $args) {
        return call_user_func_array(array($this->rs, $name), $args);
    }

    public function __get($name) {
        return $this->rs->$name;
    }

    public function __set($name, $val) {
        $this->rs->$name = $val;
    }

    /**
    * Provides the functionality of mysqli_result::fetch_all on systems without mysqlnd.
    *
    * Unlike mysqli_result::fetch_all(), returns associative arrays by default.
    *
    * See http://us3.php.net/manual/en/mysqli-result.fetch-all.php
    */
    public function fetch_all($resulttype = MYSQLI_ASSOC) {
        if ( method_exists($this->rs, 'fetch_all') ) {
            return $this->rs->fetch_all( $resulttype );
        }

        $outp = array();
        while ( $row = $this->rs->fetch_array($resulttype) )
            $outp[] = $row;

        return $outp;
    }
}