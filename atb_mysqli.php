<?php

class atb_mysqli extends mysqli {
    private $terms = array();
    private $semesters = array();
    
    public function __construct($host, $user, $password, $name, $port) {
        parent::__construct($host, $user, $password, $name, $port);
    }
    
    /**
     * Default method call handler
     *
     * Possible methods:
     *  - getAll<table_name>($row, $value):
     *      "SELECT * FROM <table_name> WHERE $row = $value"
     *  - getAll<tableName>($arr):
     *      Parses $arr into $key $value pairs:
     *      "...WHERE $key1 = $value1 AND $key2 = $value2 ..."
     *  - get<table_name>($row, $value), get<table_name($arr):
     *      Like getAll... but returns a single row.
     */
    public function __call($name, $args) {
        //FALSE == 0, but FALSE !== 0.
        if ( strpos(strtolower($name), 'getall') === 0 ) {
            $table = strtolower(substr($name, 6));
            
            if ( !is_array($args[0]) ) {
                array_unshift($args, $table);
                array_unshift($args, 'SELECT * FROM `%s` WHERE `%s` = "%s"');
                $outp = call_user_func_array(array($this, 'getAll'), $args);
            } else {
                //$args[0] should be an associative array.
                $where = $args[0];
                array_walk($where, function(&$v, $k) {
                    //TODO: should be able to handle dates?
                    if ( is_numeric($v) ) $v = "$k = $v";
                    else $v = "$k = '$v'";
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
                    if ( is_numeric($v) ) $v = "$k = $v";
                    else $v = "$k = '$v'";
                });
                $where = implode(' AND ', $where);
                
                $outp = $this->getRow('SELECT * FROM %s WHERE %s', $table, $where);
            }
            if ( $outp ) return $outp;
            else return false;
        } else {
            throw new BadMethodCallException("$name: No such method found.");
        }
    }
    
    /**
     * Returns a mysqli_result for a query
     *
     * This, getRow, and getAll need at least one parameter, an SQL query;
     * if more than 1 argument is given, we'll merge them into a query using:
     * sprintf($arg1, $arg2, ...)
     *
     * Will throw an exception if the query returns an error.
     *
     * @return mysqli_result The result of this query.
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
    
    public function query($query, $resultmode = MYSQLI_STORE_RESULT) {
        $rs = parent::query($query, $resultmode);
        if ( "object" == gettype($rs) && "mysqli_result" == get_class($rs) )
            return new atb_mysqli_result($rs);
        else return $rs;
    }
    
    /**
     * Returns a single row from a databse.
     *
     * See the documentation for getResults for details.
     * 
     * @return array The result of the query.
     */
    public function getRow() {
        if ( !func_num_args() )
            throw new BadMethodCallException("OCRA_mysqli::getRow requires at least one parameter.");
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
     * @return array The result of the query.
     */
    public function getAll() {
        if ( !func_num_args() )
            throw new BadMethodCallException("OCRA_mysqli::getAll requires at least one parameter.");
        
        $rs = call_user_func_array(array($this, 'getResults'), func_get_args());
        
        if ($rs->num_rows) {
            return $rs->fetch_all();
        }
        return false;
    }
    
    /**
     * Utility function for insert() and replace().
     *
     * @todo handle dates.
     */
    protected function input($action, $table, Array $data) {
        $cols = '`'.implode('`, `', array_keys($data)).'`';
        
        foreach ( $data as $k => $v ) {
            switch ( gettype($v) ) {
                case 'boolean':
                    //Assuming this is an ENUM('y', 'n')
                    $data[$k] = $v ? "'y'" : "'n'";
                    break;
                case 'integer':
                case 'double':
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
        return $this->input(__FUNCTION__, $table, $data);
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
     */
    public function update($table, Array $data, Array $where) {
        array_walk($data, function(&$v, $k) {
            if ( is_numeric($v) ) $v = "$k = $v";
            else $v = "$k = '$v'";
        });
        $set = implode(', ', $data);
        array_walk($where, function(&$v, $k) {
            if ( is_numeric($v) ) $v = "$k = $v";
            else $v = "$k = '$v'";
        });
        $where = implode(' AND ', $where);
        $query = 'UPDATE %s SET %s WHERE %s';
        $query = sprintf($query, $table, $set, $where);
        
        $rs = $this->query($query);
        
        if ( $this->errno ) {
            throw new Exception("Database error: ".$this->error.'; Query: "'.$query.'"');
        } else {
            return $rs;
        }
    }
}

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
            return $this->rs->fetch_all();
        }
       
        $outp = array();
        while ( $row = $this->rs->fetch_array($resulttype) )
            $outp[] = $row;
        
        return $outp;
    }
}