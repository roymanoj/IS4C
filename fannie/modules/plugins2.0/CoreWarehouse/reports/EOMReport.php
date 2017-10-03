<?php

use COREPOS\Fannie\API\item\StandardAccounting;

include(__DIR__ . '/../../../../config.php');
if (!class_exists('FannieAPI')) {
    include(dirname(__FILE__).'/../../../../classlib2.0/FannieAPI.php');
}

class EOMReport extends FannieReportPage
{
    protected $header = 'EOM Report';
    protected $title = 'EOM Report';
    public $description = '[End of Month Report] is a summary of financial activity for a month';
    public $report_set = 'Finance';
    protected $required_fields = array('month', 'year', 'store');
    protected $multi_report_mode = true;
    protected $report_headers = array(
        array('Dept#', 'Super#', 'Account#', 'Dept Name', 'Sales'),
        array('Super#', 'Sales'),
    );

    public function fetch_report_data()
    {
        try {
            $month = $this->form->month;
            $year = $this->form->year;
            $store = $this->form->store;
        } catch (Exception $ex) {
            return array(array());
        }

        $tstamp = mktime(0,0,0,$month,1,$year);
        $start = date('Y-m-01', $tstamp);
        $end = date('Y-m-t', $tstamp);
        $idStart = date('Ym01', $tstamp);
        $idEnd = date('Ymt', $tstamp);
        $dlog = DTransactionsModel::selectDLog($start, $end);
        $warehouse = $this->config->get('PLUGIN_SETTINGS');
        $warehouse = $warehouse['WarehouseDatabase'];
        $warehouse .= $this->connection->sep();

        $reports = array();

        $query1="SELECT t.department,
            s.superID,
            d.salesCode,d.dept_name,
            SUM(t.total) AS ttl,
            t.store_id
        FROM {$warehouse}sumDeptSalesByDay AS t
            INNER JOIN departments as d ON t.department = d.dept_no
            LEFT JOIN MasterSuperDepts AS s ON s.dept_ID = t.department    
        WHERE date_id BETWEEN ? AND ?
            AND " . DTrans::isStoreID($store, 't') . "
            AND t.department <> 0
        GROUP BY s.superID,
            t.department,
            d.dept_name,
            d.salesCode,
            t.store_id
        ORDER BY s.superID, t.department";
        $prep = $this->connection->prepare($query1);
        $res = $this->connection->execute($prep, array($idStart, $idEnd, $store));
        $sales = array();
        $supers = array();
        $pcodes = array();
        $misc = array();
        while ($row = $this->connection->fetchRow($res)) {
            $code = StandardAccounting::extend($row['salesCode'], $row['store_id']);
            if ($row['superID'] == 0) {
                $misc[] = array(
                    $row['department'],
                    $row['superID'],
                    $code,
                    $row['dept_name'],
                    sprintf('%.2f', $row['ttl']),
                );
                continue;
            }
            $sales[] = array(
                $row['department'],
                $row['superID'],
                $code,
                $row['dept_name'],
                sprintf('%.2f', $row['ttl']),
            );
            if (!isset($supers[$row['superID']])) {
                $supers[$row['superID']] = array($row['superID'], 0);
            }
            $supers[$row['superID']][1] += $row['ttl'];
            if (!isset($pcodes[$code])) {
                $pcodes[$code] = array($code, $row['superID'], 0);
            }
            $pcodes[$code][2] += $row['ttl'];
        }
        $reports[] = $sales;

        $reports[] = $this->dekey_array($supers);

        $query2 = "SELECT 
            t.TenderName,
            -sum(d.total) as total, SUM(d.quantity) AS qty
        FROM {$warehouse}sumTendersByDay AS d
            left join tenders as t ON d.trans_subtype=t.TenderCode
        WHERE d.date_id BETWEEN ? AND ?
            AND " . DTrans::isStoreID($store, 'd') . "
        AND d.trans_subtype <> 'MA'
        AND d.trans_subtype <> 'IC'
        GROUP BY t.TenderName";
        $prep = $this->connection->prepare($query2);
        $res = $this->connection->execute($prep, array($idStart, $idEnd, $store));
        $tenders = array();
        while ($row = $this->connection->fetchRow($res)) {
            $tenders[] = array(
                $row['TenderName'],
                sprintf('%.2f', $row['total']),
                $row['qty'],
            );
        }
        $queryStoreCoupons = "
            SELECT 
                CASE 
                    WHEN h.description is NOT NULL THEN h.description
                    WHEN d.upc <> '0' THEN d.upc
                    ELSE 'Generic InStore Coupon'
                END as TenderName,
                -sum(d.total) as total, 
                COUNT(d.total) AS qty
            FROM $dlog AS d
                LEFT JOIN houseCoupons AS h ON d.upc=concat('00499999', lpad(convert(h.coupID, char), 5, '0'))
            WHERE d.tdate BETWEEN ? AND ?
                AND " . DTrans::isStoreID($store, 'd') . "
                AND d.trans_type='T'
                AND d.trans_subtype = 'IC'
                AND (d.upc = '0' OR d.upc LIKE '00499999%')
                and d.total <> 0
            GROUP BY TenderName";
        $prep = $this->connection->prepare($queryStoreCoupons);
        $res = $this->connection->execute($prep, array($start . ' 00:00:00', $end . ' 23:59:59', $store));
        while ($row = $this->connection->fetchRow($res)) {
            $tenders[] = array(
                $row['TenderName'],
                sprintf('%.2f', $row['total']),
                $row['qty'],
            );
        }
        $reports[] = $tenders;

        $reports[] = $this->dekey_array($pcodes);
        $reports[] = $this->dekey_array($misc);

        $query8 = "SELECT     m.memDesc, SUM(d.total) AS Discount 
            FROM {$warehouse}sumDiscountsByDay d INNER JOIN
              memtype m ON d.memType = m.memtype
        WHERE d.date_id BETWEEN ? AND ?
        AND " . DTrans::isStoreID($store, 'd') . "
        GROUP BY d.memType
        ORDER BY d.memType";
        $prep = $this->connection->prepare($query8);
        $res = $this->connection->execute($prep, array($idStart, $idEnd, $store));
        $discounts = array();
        while ($row = $this->connection->fetchRow($res)) {
            $discounts[] = array(
                $row['memDesc'],
                sprintf('%.2f', $row['Discount']),
            );
        }
        $reports[] = $discounts;

        $query21 = "SELECT m.memDesc, COUNT(*) as qty
            FROM {$warehouse}transactionSummary AS d 
                left join memtype m on d.memType = m.memtype
            WHERE date_id BETWEEN ? AND ? AND (d.memType <> 4)
                AND " . DTrans::isStoreID($store, 'd') . "
            GROUP BY m.memdesc";
        $prep = $this->connection->prepare($query21);
        $res = $this->connection->execute($prep, array($idStart, $idEnd, $store));
        $transactions = array();
        while ($row = $this->connection->fetchRow($res)) {
            $transactions[] = array(
                $row['memDesc'],
                $row['qty'],
            );
        }
        $reports[] = $transactions;

        $query11 = "SELECT  sum(total) as tax_collected
            FROM $dlog as d 
            WHERE d.tdate BETWEEN ? AND ?
            AND " . DTrans::isStoreID($store, 'd') . "
            AND (d.upc = 'tax')
            GROUP BY d.upc";
        $prep = $this->connection->prepare($query11);
        $tax = $this->connection->getValue($prep, array($start . ' 00:00:00', $end . ' 23:59:59', $store));
        $misc = array();
        $misc[] = array('Actual Tax Collected', sprintf('%.2f', $tax));
        $queryRRR = "
            SELECT sum(case when volSpecial is null then 0 
                when volSpecial > 100 then 1
                else volSpecial end) as qty
            from {$dlog} as t
            where upc = 'RRR'
            and t.tdate BETWEEN ? AND ?
            AND " . DTrans::isStoreID($store, 't');
        $prep = $this->connection->prepare($queryRRR);
        $rrr = $this->connection->getValue($prep, array($start . ' 00:00:00', $end . ' 23:59:59', $store));
        $misc[] = array('RRR Coupons Redeemd', sprintf('%.2f', $rrr));
        $reports[] = $misc;

        $dtrans = DTransactionsModel::selectDTrans($start, $end);
        $newTaxQ = $this->connection->prepare("SELECT description,
                        SUM(regPrice) AS ttl,
                        numflag AS taxID
                    FROM {$dtrans} AS t
                    WHERE datetime BETWEEN ? AND ?
                        AND " . DTrans::isStoreID($store, 't') . "
                        AND upc='TAXLINEITEM'
                        AND " . DTrans::isNotTesting() . "
                    GROUP BY taxID, description");
        $res = $this->connection->execute($newTaxQ, array($start . ' 00:00:00', $end . ' 23:59:59', $store));
        $rates = array(
            1 => array('Regular', 0, 'includes'=>array('State', 'City', 'County')),
            2 => array('Deli', 0, 'includes'=>array('State', 'City', 'County', 'Deli')),
        );
        $collectors = array(
            'State' => array(0.06875, 0),
            'City' => array(0.01, 0),
            'Deli' => array(0.0225, 0),
            'County' => array(0.005, 0),
        );
        if ($startID >= 20171001) {
            $collectors['County'] = array(0, 0);
        }
        while ($row = $this->connection->fetchRow($res)) {
            $taxID = $row['taxID'];
            $rates[$taxID][1] = $row['ttl'];
        }
        $taxInfo = array();
        foreach ($rates as $rate) {
            $summary = array('Tax on ' . $rate[0] . ' items');
            $summary[] = sprintf('%.2f', $rate[1]);
            $effectiveRate = 0;
            foreach ($rate['includes'] as $govt) {
                $effectiveRate += $collectors[$govt][0];
            }
            $summary[] = 'Taxable sales on ' . $rate[0] . ' items';
            $summary[] = sprintf('%.2f', $rate[1] / $effectiveRate);
            $summary['meta'] = FannieReportPage::META_BOLD;
            $taxInfo[] = $summary;
            foreach ($rate['includes'] as $govt) {
                $record = array($govt . ' Tax Amount');
                $govtTax = $rate[1] * ($collectors[$govt][0] / $effectiveRate);
                $record[] = sprintf('%.2f', $govtTax);
                $record[] = $govt . ' Taxable Sales';
                $record[] = sprintf('%.2f', $govtTax / $collectors[$govt][0]);
                $taxInfo[] = $record;
                $collectors[$govt][1] += $govtTax;
            }
        }
        foreach ($collectors as $govt => $info) {
            $record = array('Total ' . $govt . ' Tax Collected');
            $record[] = sprintf('%.2f', $info[1]);
            $record[] = $govt . ' Taxable Sales';
            $record[] = sprintf('%.2f', $info[1] / $info[0]);
            $record['meta'] = FannieReportPage::META_BOLD;
            $taxInfo[] = $record;
        }
        $reports[] = $taxInfo;

        return $reports;
    }

    public function calculate_footers($data)
    {
        switch($this->multi_counter) {
            case 1:
                $this->report_headers[0] = array('Dept#', 'Super#', 'Account#', 'Dept Name', 'Sales');
                $sum = array_reduce($data, function($c, $i) { return $c + $i[4]; });
                return array('Total', '', '', '', $sum);
            case 2:
                $this->report_headers[0] = array('Super#', 'Sales');
                $sum = array_reduce($data, function($c, $i) { return $c + $i[1]; });
                return array('Total', $sum);
            case 3:
                $this->report_headers[0] = array('Tender', 'Amount', 'Count');
                $sum = array_reduce($data, function($c, $i) { return $c + $i[1]; });
                return array('Total', $sum, '');
            case 4:
                $this->report_headers[0] = array('Account#', 'Super#', 'Sales');
                $sum = array_reduce($data, function($c, $i) { return $c + $i[2]; });
                return array('Total', '', $sum);
            case 5:
                $this->report_headers[0] = array('Dept#', 'Super#', 'Account#', 'Dept Name', 'Sales');
                $sum = array_reduce($data, function($c, $i) { return $c + $i[4]; });
                return array('Total', '', '', '', $sum);
            case 6:
                $this->report_headers[0] = array('Customer Type', 'Discounts');
                $sum = array_reduce($data, function($c, $i) { return $c + $i[1]; });
                return array('Total', $sum);
            case 7:
                $this->report_headers[0] = array('Customer Type', 'Transactions');
                $sum = array_reduce($data, function($c, $i) { return $c + $i[1]; });
                return array('Total', $sum);
            case 8:
                $this->report_headers[0] = array('Miscellaneous', '$');
                break;
            case 9:
                $this->report_headers[0] = array();
                break;
        }

        return array();
    }

    public function form_content()
    {
        $opts = array_reduce(range(1, 12), function ($c, $i) { 
            $lm = date('n', mktime(0,0,0,date('n')-1,1,2000));
            $sel = $lm == $i ? 'selected' : '';
            return $c . "<option value=\"{$i}\" {$sel}>" . date('F', mktime(0,0,0,$i,1,2000)); 
        });
        $stores = FormLib::storePicker();
        $year = date('Y', mktime(0,0,0, date('n')-1, 1, date('Y')));

        return <<<HTML
<form method="get">
    <div class="form-group">
        <label>Month</label>
        <select name="month" class="form-control">
            {$opts}
        </select>
    </div>
    <div class="form-group">
        <label>Year</label>
        <input type="text" name="year" class="form-control" value="{$year}" />
    </div>
    <div class="form-group">
        <label>Store</label>
        {$stores['html']}
    </div>
    <div class="form-group">
        <button type="submit" class="btn btn-default btn-core">Submit</button>
    </div>
</form>
HTML;
    }
}

FannieDispatch::conditionalExec();

