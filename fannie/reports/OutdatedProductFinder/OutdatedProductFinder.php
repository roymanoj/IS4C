 <?php
/*******************************************************************************

    Copyright 2013 Whole Foods Co-op

    This file is part of CORE-POS.

    CORE-POS is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    CORE-POS is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    in the file license.txt along with IT CORE; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*********************************************************************************/

include(dirname(__FILE__) . '/../../config.php');
if (!class_exists('FannieAPI')) {
    include($FANNIE_ROOT.'classlib2.0/FannieAPI.php');
}

class SaleItemsByDate extends FannieReportPage 
{
    public $description = '[Scan Tools] Finds products not sold in over 12 months, marks them as not-in-use';
    public $report_set = 'Scan Tools';
    public $themed = true;

    protected $report_headers = array('Items');
    protected $sort_direction = 1;
    protected $title = "Fannie : Outdated Product Finder";
    protected $header = "Outdated Product Finder";

    public function fetch_report_data()
    {        
	$item = array();
        
        
        global $FANNIE_OP_DB, $FANNIE_URL;
        $dbc = FannieDB::get($FANNIE_OP_DB);
        $upc = array();
        $check = array();
        $datetime = "2015-07-24 00:00:00";
   
        // Find Items not in use in past 12 months
        $query = "SELECT upc, last_sold 
                FROM is4c_op.products 
                WHERE last_sold < '2014-07-23 00:00:00' and inUse = '1'
                GROUP BY upc;
                ";
        $result = $dbc->query($query);
        while ($row = $dbc->fetch_row($result)) {
            $upc[] = $row['upc'];
        }
        print count($upc) . " items found that are in use and have not been sold in over 1 year.<br><br>";
        
        // Change found items to 'not-in-use'
        for($i=0; $i<count($upc); $i++){
            $query = "UPDATE is4c_op.products
                    SET inUse = '0'
                    WHERE upc = $upc[$i];
                    ";
        }
        
        // Check to see if the script made changes
        $query = "SELECT upc, last_sold 
                FROM is4c_op.products 
                WHERE last_sold < '2014-07-23 00:00:00' and inUse = '1'
                GROUP BY upc;
                ";
        $result = $dbc->query($query);
        while ($row = $dbc->fetch_row($result)) {
            $check[] = $row['upc'];
        }
        print count($check) . " there are now items found that are in use and have not been sold in over 1 year.<br>";
        print "If this number is greater than zero, this script did not work<br>";
    }
}

FannieDispatch::conditionalExec();

