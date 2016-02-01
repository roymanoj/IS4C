<?php
/*******************************************************************************

    Copyright 2012 Whole Foods Co-op

    This file is part of CORE-POS.

    IT CORE is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    IT CORE is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    in the file license.txt along with IT CORE; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*********************************************************************************/

include(dirname(__FILE__).'/../../../config.php');
if (!class_exists('FannieAPI')) {
    include_once($FANNIE_ROOT.'classlib2.0/FannieAPI.php');
}
if (!class_exists('wfcuRegistryModel')) {
    include_once($FANNIE_ROOT.'modules/plugins2.0/wfcu2/wfcuRegistryModel.php');
}

/**
  @class HouseCouponEditor
*/
class wfcu2 extends FanniePage 
{

    public $description = "[Module] for managing WFC-U Class Sign-In";
    public $themed = true;

    protected $must_authenticate = true;
    protected $auth_classes = array('tenders');

    protected $header = "Fannie :: WFC-U Class Registry";
    protected $title = "WFC Class Sign-in";

    private $display_function;
    private $coupon_id;
    private $plu;

    public function preprocess()
    {
        $this->display_function = 'listClasses';
        
        return true;
    }

    public  function body_content()
    {
        $func = $this->display_function;

        return $this->$func();
    }

    private function listClasses()
    {
        $FANNIE_URL = $this->config->get('URL');
        echo "<div id=\"line-div\"></div>";
        
        $dbc = FannieDB::get($this->config->get('OP_DB'));
        
        $query = $dbc->prepare("
            SELECT 
                pu.description, 
                p.upc,
                p.size
            FROM products AS p 
                LEFT JOIN productUser AS pu ON pu.upc=p.upc 
            WHERE p.description LIKE 'class -%' 
                    AND p.inUse=1
            ORDER BY pu.description DESC;
            ");
        $result = $dbc->execute($query);
        while($row = $dbc->fetch_row($result)){
            $className[] = substr($row['description'], 11, 100);
            $classUPC[] = substr($row['upc'], 5, 13);
            $classDate[] = substr($row['description'], 0, 8);
            $classSize[] = $row['size'];
        }
        
        $ret .= '<div class=\'container\'><form method=\'get\'><select class=\'form-control\' name=\'class_plu\'>';
        $ret .= '<option value=\'\'>Choose a class...</option>';
        foreach ($className as $key => $name) {
            $ret .= '<option value=\'' . $key . '\'>' . $classDate[$key] . " :: " . $name . '</option>';
        }
        
        $ret .= '<input class=\'btn btn-default\' type=\'submit\' value=\'Open Class Registry\'>';
        $ret .= '</select></form></div>';
        
        $key = $_GET['class_plu'];
        $plu = $classUPC[$key];
        $this->plu = $classUPC[$key];
        echo "private variable plu = "; var_dump($this->plu);
        
        //* Create table if it doesn't exist
        $p1 = $dbc->prepare("CREATE TABLE IF NOT EXISTS
            wfcuRegistry (
                upc VARCHAR(13), 
                class VARCHAR(255), 
                first_name VARCHAR(30),
                last_name VARCHAR(30),
                first_opt_name VARCHAR(30),
                last_opt_name VARCHAR(30),
                phone VARCHAR(30),
                opt_phone VARCHAR(30),
                card_no INT(11),
                payment VARCHAR(30),
                refunded INT(1),
                modified DATETIME,
                store_id SMALLINT(6),
                start_time TIME,
                date_paid DATETIME,
                seat INT(50),
                seatType INT(5)
            );   
        ");
        $r1 = $dbc->execute($p1);
        if (mysql_errno() > 0) {
            echo mysql_errno() . ": " . mysql_error(). "<br>";
        }
        
        //* Populate Seats
        $pCheck = $dbc->prepare("
            SELECT count(seat)
            FROM wfcuRegistry
            WHERE upc = {$plu}
                AND seatType=1
        ;");
        $rCheck = $dbc->execute($pCheck);
        while ($row = $dbc->fetch_row($rCheck)) {
            $numSeats = $row['count(seat)'];
        }
        $pCheck = $dbc->prepare("
            SELECT size
            FROM products
            WHERE upc = {$plu}
        ;");
        $rCheck = $dbc->execute($pCheck);
        while ($row = $dbc->fetch_row($rCheck)) {
            $classSize = $row['size'];
        }
        
        $sAddSeat = "INSERT INTO wfcuRegistry (upc, seat, seatType) VALUES ";
        for ($i=$numSeats; $i<$classSize; $i++) {
                    $sAddSeat .= " ( " . $plu . ", " . ($i+1) . ", 1) ";
                    if (($i+1)<$classSize) {
                        $sAddSeat .= ", ";
                    }
        }
        if ($numSeats != $classSize) {
            $pAddSeat = $dbc->prepare("{$sAddSeat}");  
            $rAddSeat = $dbc->execute($pAddSeat);
        }
        
        
        $prep = $dbc->prepare("SELECT count(seat) FROM wfcuRegistry WHERE seatType=0;");
        $resp = $dbc->execute($prep);
        while ($row = $dbc->fetch_row($resp)) {
            $waitSize = $row['count(seat)'];
        }
        if ($waitSize == 0 || $waitSize = NULL) {
            $prep = $dbc->prepare("INSERT INTO wfcuRegistry (upc, seat, seatType) VALUES ({$plu}, 1, 0)");
            $resp = $dbc->execute($prep);
        }
        
        if ($key) {
            
            $ret .= "<h2 align=\"center\">" . $className[$key] . "</h2>";
            $ret .= "<h3 align=\"center\">" . $classDate[$key] . "</h3>";
            $ret .= "<h5 align=\"center\"> Plu for this class: " . $plu . "</h5>";
            $ret .= "<div id=\"line-div\"></div>";
            
            $prep = $dbc->prepare("
                SELECT upc,
                    class,
                    first_name,
                    last_name,
                    first_opt_name,
                    last_opt_name,
                    phone,
                    opt_phone,
                    card_no,
                    payment,
                    refunded,
                    modified,
                    store_id,
                    start_time,
                    seat
                FROM wfcuRegistry
                WHERE upc = {$plu}
                    AND seatType=1
                ORDER BY seat
            ;");
            $resp = $dbc->execute($prep);
            while ($row = $dbc->fetch_row($resp)) {
                $class = $row['class'];
                $first_name[] = $row['first_name'];
                $last_name[] = $row['last_name'];
                $first_opt_name[] = $row['first_opt_name'];
                $last_opt_name[] = $row['last_opt_name'];
                $phone[] = $row['phone'];
                $opt_phone[] = $row['opt_phone'];
                $card_no[] = $row['card_no'];
                $payment[] = $row['payment'];
                $refunded[] = $row['refunded'];
                $modified[] = $row['modified'];
                $store_id = $row['store_id'];
                $classDate = substr($row['class'], 0, 8);
                $start = $row['start_time'];
                $datePaid[] = $row['date_paid'];
                $seat[] = $row['seat'];
                $seatType[] = $row['seatType'];
            }
            if (mysql_errno() > 0) {
                echo mysql_errno() . ": " . mysql_error(). "<br>";
            }
            
            
            $ret .= '<table class="table">';
            $ret .= '
                <thead><tr><th>Class Registry  <th>
                <tr><th>Seat</th>
                <th>First</th>
                <th>Last</th>
                <th>Member #</th>
                <th>Phone Number</th>
                <th>Payment Type</th>
                <th>opt. Attendee</th>
                <th>opt. Attendee Phone</th></thead>
            ';
            foreach ($seat as $key => $value) {
                $ret .= '<tr><td>' . $value . '</td>';
                $ret .= '<td>' . $first_name[$key] . '</td>';
                $ret .= '<td>' . $last_name[$key] . '</td>';
                $ret .= '<td>' . $card_no[$key] . '</td>';
                $ret .= '<td>' . $phone[$key] . '</td>';
                $ret .= '<td>' . $first_opt_name[$key] . '</td>';
                $ret .= '<td>' . $last_opt_name[$key] . '</td>';
                $ret .= '<td>' . $opt_phone[$key] . '</tr>';
            }
            $ret .= '</table>';
            
            $ret .= '<table class="table table-striped">';
            $ret .= '
                <thead><tr><th>Waiting List<th>
                <tr><th>Seat</th>
                <th>First</th>
                <th>Last</th>
                <th>Member #</th>
                <th>Phone Number</th>
                <th>Payment Type</th>
                <th>opt. Attendee</th>
                <th>opt. Attendee Phone</th></thead>
            ';
            foreach ($seat as $key => $value) {
                if ($seatType[$key] == 0) {
                    $ret .= '<tr><td>' . $value . '</td>';
                    $ret .= '<td>' . $first_name[$key] . '</td>';
                    $ret .= '<td>' . $last_name[$key] . '</td>';
                    $ret .= '<td>' . $card_no[$key] . '</td>';
                    $ret .= '<td>' . $phone[$key] . '</td>';
                    $ret .= '<td>' . $first_opt_name[$key] . '</td>';
                    $ret .= '<td>' . $last_opt_name[$key] . '</td>';
                    $ret .= '<td>' . $opt_phone[$key] . '</tr>';
                }
            }
            $ret .= '</table>';
            return $ret;
            $ret .= '<table class="table table-striped">';
            $ret .= '
                <thead><tr><th>Cancellations<th>
                <tr><th>Seat</th>
                <th>First</th>
                <th>Last</th>
                <th>Member #</th>
                <th>Phone Number</th>
                <th>Payment Type</th>
                <th>opt. Attendee</th>
                <th>opt. Attendee Phone</th></thead>
            ';
           
            $ret .= '</table>';
            
        }
        
        return $ret;
        
        $items = new wfcuRegistryModel($dbc);
        $items->upc($this->plu);

        $ret = '<div id="alert-area"></div>
            <table class="table tablesorter">';
        $ret .= '<thead><tr>
            <th>PLU</th
            </tr></thead>';
        $ret .= '<tbody>';
        foreach ($items->find() as $item) {
            $ret .= sprintf('<tr>
                <td><span class="collapse">%s</span>
                    <input type="text" class="form-control input-sm plu-field" name="editSKU" value="%s" size="13" /></td>
                </tr>',
                $item->upc(),
                $item->upc()
            );
        }
        $ret .= '</tbody></table>';
        //$ret .= '<input type="hidden" id="vendor-id" value="' . $this->upc . '" />';
        //$ret .= '<p><a href="VendorIndexPage.php?vid=' . $this->upc . '" class="btn btn-default">Home</a></p>';
        $this->add_onload_command('itemEditing();');
        $this->add_script('../../src/javascript/tablesorter/jquery.tablesorter.js');
        $this->addCssFile('../../src/javascript/tablesorter/themes/blue/style.css');
        $this->add_onload_command("\$('.tablesorter').tablesorter({sortList:[[0,0]], widgets:['zebra']});");
        
        $dbc->close();
        
        return $ret;
    }
    
}

FannieDispatch::conditionalExec();
