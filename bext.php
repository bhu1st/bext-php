<?php

/**
 * The .bext file parser.
 *
 * This script provides common functions to parse and process the BEXT file format specification.
 *
 * @copyright Copyright (c) 2024 Bhupal Sapkota
 * @license   http://www.opensource.org/licenses/mit-license.php The MIT License
 * @author    Bhupal Sapkota [www.bhupal.net]
 * @url       https://github.com/bhu1st/bext-php
 */

// Parse the .bext file
function parseBextFile($filename)
{
    $data = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $transactions = [];
    $lastFullDateYear = null; // Keep track of the year from the last full date

    foreach ($data as $line) {

        $line = trim($line);
		
        if ($line === '' || $line[0] === '*') {
            // Skip empty lines or comment lines starting with *
            continue;
        }

        $entry = [
            'type' => '',
            'amount' => 0,
            'budget' => 0,
            'accounts' => [],
            'persons' => [],
            'categories' => [],
            'remarks' => 'Other',
            'method' => 'Other',
            'timestamp' => null            
        ];

        // Determine type (+, -, $)
        if (preg_match('/^([+\-$])/', $line, $matches)) {
            $entry['type'] = $matches[1];
        }

        // Extract amount or budget
        if (preg_match('/^[+\-]\s*(\d+(\.\d{1,2})?)/', $line, $matches)) {
            $entry['amount'] = (float)$matches[1];
        } elseif (preg_match('/^\$\s*(\d+(\.\d{1,2})?)/', $line, $matches)) {
            $entry['budget'] = (float)$matches[1];
        }

        // Extract persons
        if (preg_match('/@([^#\[\]~?:]+)/', $line, $matches)) {
            $entry['persons'] = array_map('trim', explode(';', $matches[1]));
        }

        // Extract accounts
        if (preg_match('/~([^#@\[\]?:]+)/', $line, $matches)) {
            $accounts = array_map('trim', explode(';', $matches[1]));
            foreach ($accounts as $account) {
                if (strpos($account, '>') !== false) {
                    [$parent, $child] = array_map('trim', explode('>', $account, 2));
                    if (!isset($entry['accounts'][$parent])) {
                        $entry['accounts'][$parent] = [];
                    }
                    $entry['accounts'][$parent][$child] = true;
                } else {
                    $entry['accounts'][$account] = true;
                }
            }
        }

        // Extract categories
        if (preg_match('/#([^@\[\]~?:]+)/', $line, $matches)) {
            $categories = array_map('trim', explode(';', $matches[1]));
            foreach ($categories as $category) {
                if (strpos($category, '>') !== false) {
                    [$parent, $child] = array_map('trim', explode('>', $category, 2));
                    if (!isset($entry['categories'][$parent])) {
                        $entry['categories'][$parent] = [];
                    }
                    $entry['categories'][$parent][$child] = true; // Placeholder for the nested structure.
                } else {
                    $entry['categories'][$category] = true; // Placeholder for flat structure.
                }
            }
        }

        // Extract remarks
        if (preg_match('/\?((?:[^#\[\]~@:])+)/', $line, $matches)) {
            $entry['remarks'] = trim($matches[1]);
        }

        // Match payment method and handle : in the timestamp
        if (preg_match('/:([^\s#\[\]~?:]+)(?=\s|$)/', $line, $matches)) {
            $entry['method'] = trim($matches[1]);
        }

        // Extract timestamp
        if (preg_match('/\[(.*?)\]/', $line, $matches)) {
            $timestamp = $matches[1];
            if (preg_match('/^\d{1,2}\/\d{1,2}$/', $timestamp)) {
                // If timestamp is in short format (mm/dd, m/d, mm/d or m/dd), add inferred time and year
                if ($lastFullDateYear) {
                    $timestamp = $lastFullDateYear . '/' . $timestamp . ' 18:00'; //set default time to 6pm.
                } else {
                    throw new Exception("Short date format used without a preceding full date to infer the year.");
                }
            } elseif (preg_match('/^\d{1,2}\/\d{1,2}\s\d{2}:\d{2}$/', $timestamp)) {
                // If timestamp is in short format (mm/dd HH:ii), add inferred year
                if ($lastFullDateYear) {
                    $timestamp = $lastFullDateYear . '/' . $timestamp;
                } else {
                    throw new Exception("Short date format used without a preceding full date to infer the year.");
                }
            } elseif (preg_match('/^\d{4}\/\d{2}\/\d{2}\s\d{2}:\d{2}$/', $timestamp)) {
                // If timestamp is in full format (yyyy/mm/dd HH:ii), update last full date year
                $lastFullDateYear = substr($timestamp, 0, 4);
            }
            $entry['timestamp'] = $timestamp;
        }

        $transactions[] = $entry;
    }

    return $transactions;
}


// Calculate totals
function calculateTotals($transactions)
{
    $totals = [
        'income' => 0,
        'expense' => 0,
        'budget' => 0,
        'category' => [],
        'person' => [],
        'account' => [],
        'method' => [],
        'budget_category' => [],
        'budget_person' => [],
        'budget_account' => []
    ];

    // Iterate over each transaction and calculate totals
    foreach ($transactions as $entry) {

        $amount = $entry['amount'];
        $budget = $entry['budget'];
        $type = $entry['type'];


        // Overall income, expense, and budget
        if ($type === '+') {
            $totals['income'] += $amount;
        } elseif ($type === '-') {
            $totals['expense'] += $amount;
        } elseif ($type === '$') {
            $totals['budget'] += $budget;
        }

        // Sum totals by category
        foreach ($entry['categories'] as $parent => $children) {
            if (!isset($totals['category'][$parent])) {
                $totals['category'][$parent] = [':total' => 0];
            }
            if (is_array($children)) {
                foreach ($children as $child => $_) {
                    if (!isset($totals['category'][$parent][$child])) {
                        $totals['category'][$parent][$child] = 0;
                    }

                    if ($type === '+') {
                        $totals['category'][$parent][$child] += $amount;
                        $totals['category'][$parent][':total'] += $amount;
                    } elseif ($type === '-') {
                        $totals['category'][$parent][$child] -= $amount;
                        $totals['category'][$parent][':total'] -= $amount;
                    }
                    
                }
            } else {

                if ($type === '+') {
                    $totals['category'][$parent][':total'] += $amount;                    
                } elseif ($type === '-') {
                    $totals['category'][$parent][':total'] -= $amount;
                }               
            }

            // Budget-specific aggregation for categories
            if ($entry['type'] === '$') {
                if (!isset($totals['budget_category'][$parent])) {
                    $totals['budget_category'][$parent] = [':total' => 0];
                }
                if (is_array($children)) {
                    foreach ($children as $child => $_) {
                        if (!isset($totals['budget_category'][$parent][$child])) {
                            $totals['budget_category'][$parent][$child] = 0;
                        }
                        
                        $totals['budget_category'][$parent][$child] += $budget;
                        $totals['budget_category'][$parent][':total'] += $budget;
                    }
                } else {
                    $totals['budget_category'][$parent][':total'] += $budget;
                }
            }
        }

        // Sum totals by account
        foreach ($entry['accounts'] as $parent => $children) {
            if (!isset($totals['account'][$parent])) {
                $totals['account'][$parent] = [':total' => 0];
            }
            if (is_array($children)) {
                foreach ($children as $child => $_) {
                    if (!isset($totals['account'][$parent][$child])) {
                        $totals['account'][$parent][$child] = 0;
                    }

                    if ($type === '+') {
                        $totals['account'][$parent][$child] += $amount;
                    } elseif ($type === '-') {
                        $totals['account'][$parent][$child] -= $amount;
                    }
                    
                }
            } else 
            {
                if ($type === '+') {
                    $totals['account'][$parent][':total'] += $amount;
                } elseif ($type === '-') {
                    $totals['account'][$parent][':total'] -= $amount;
                }               
            }

            // Budget-specific aggregation for accounts
            if ($entry['type'] === '$') {
                if (!isset($totals['budget_account'][$parent])) {
                    $totals['budget_account'][$parent] = ['total' => 0];
                }
                if (is_array($children)) {
                    foreach ($children as $child => $_) {
                        if (!isset($totals['budget_account'][$parent][$child])) {
                            $totals['budget_account'][$parent][$child] = 0;
                        }
                        $totals['budget_account'][$parent][$child] += $budget;
                    }
                } else {
                    $totals['budget_account'][$parent]['total'] += $budget;
                }
            }
        }

        // Sum totals by person
        foreach ($entry['persons'] as $person) {
            if (!isset($totals['person'][$person])) {
                $totals['person'][$person] = 0;
            }
            if ($type === '+') {
                $totals['person'][$person] += $amount;
            } elseif ($type === '-') {
                $totals['person'][$person] -= $amount;
            }
        }

        // Sum totals by payment method
        if ($entry['method'] !== 'Other') {
            if (!isset($totals['method'][$entry['method']])) {
                $totals['method'][$entry['method']] = 0;
            }
                        
            $totals['method'][$entry['method']] += $amount;
        }

        // Sum budget totals by person
        if ($type === '$') {

            // Budget by Person
            foreach ($entry['persons'] as $person) {
                if (!isset($totals['budget_person'][$person])) {
                    $totals['budget_person'][$person] = 0;
                }
                $totals['budget_person'][$person] += $budget;
            }
        }
    }

    return $totals;
}

// Filter parsed data by account, person, category or payment method.
function getTransactionsByFilter($transactions, $filter)
{
	$filters = preg_split('/\s+/', trim($filter));
    $dateFilter = null;
    $typeFilters = [];

    // Separate date and type filters
    foreach ($filters as $filter) {
        if (preg_match('/^(-?)([dwmqy])$/i', $filter)) {
            $dateFilter = $filter;
        } elseif (preg_match('/^[@#~:]/', $filter)) {
            $typeFilters[] = $filter;
        }
    }
	
	
    $filteredTransactions = [];
	
    // Loop through all transactions and filter based on the filter type
    foreach ($transactions as $tx) {

        $match = true;
		
		
		// Apply date filter
        if ($dateFilter && isset($tx['timestamp'])) {
            $ts = strtotime($tx['timestamp']);
            $now = time();

            switch ($dateFilter) {
                case 'd': $match = date('Y-m-d', $ts) === date('Y-m-d'); break;
                case '-d': $match = date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day')); break;
                case 'w': $match = date('oW', $ts) === date('oW'); break;
                case '-w': $match = date('oW', $ts) === date('oW', strtotime('-1 week')); break;
                case 'm': $match = date('Y-m', $ts) === date('Y-m'); break;
                case '-m': $match = date('Y-m', $ts) === date('Y-m', strtotime('-1 month')); break;
                case 'y': $match = date('Y', $ts) === date('Y'); break;
                case '-y': $match = date('Y', $ts) === date('Y', strtotime('-1 year')); break;
                case 'q':
                    $month = date('n', $ts);
                    $quarter = ceil($month / 3);
                    $match = (date('Y', $ts) === date('Y') && $quarter === ceil(date('n') / 3));
                    break;
                case '-q':
                    $month = date('n', $ts);
                    $quarter = ceil($month / 3);
                    $lastQuarter = ceil((date('n', strtotime('-3 months'))) / 3);
                    $match = (date('Y', $ts) === date('Y', strtotime('-3 months')) && $quarter === $lastQuarter);
                    break;
            }

            if (!$match) continue;
        }
		
		// Apply type filters
        foreach ($typeFilters as $filter) {
            $prefix = $filter[0];
            $value = substr($filter, 1);
            $valueParts = explode('>', $value);
            $main = $valueParts[0];
            $sub = $valueParts[1] ?? null;

            switch ($prefix) {
                case '@': // person
                    if (!in_array($main, $tx['persons'])) {
                        $match = false;
                    }
                    break;

                case '#': // category/subcategory
                    $found = false;
                    foreach ($tx['categories'] ?? [] as $cat => $subs) {
                        if ($cat === $main && $sub === null) $found = true;
                        elseif (is_array($subs) && $sub !== null && array_key_exists($sub, $subs)) $found = true;
                    }
                    if (!$found) $match = false;
                    break;

                case '~': // account/subaccount
                    $found = false;
                    foreach ($tx['accounts'] ?? [] as $acc => $subs) {
                        if ($acc === $main && $sub === null) $found = true;
                        elseif (is_array($subs) && $sub !== null && array_key_exists($sub, $subs)) $found = true;
                    }
                    if (!$found) $match = false;
                    break;

                case ':': // payment method
                    if (($tx['method'] ?? 'Other') !== $main) {
                        $match = false;
                    }
                    break;
            }

            if (!$match) break;
        }
		
		if ($match) {
            $filteredTransactions[] = $tx;
        }
		
    }

    return ['filter' => $filter, 'totals' => calculateTotals($filteredTransactions), 'transactions' => $filteredTransactions];
}


function displayTransactions($transactions, $json = false)
{
    if ($json) {
        return json_encode($transactions, JSON_PRETTY_PRINT);
    } else {
        // Display results
        echo "Transactions for " . $transactions['filter'] . ":\n";
        foreach ($transactions['transactions'] as $entry) {
            echo "-> " . $entry['type'] . " " . $entry['amount'] . " @" . implode(';', $entry['persons']) . " #" . implode(';', $entry['categories']) . " ?" . $entry['remarks'] . "\n";
        }

        echo "\nTotals:\n";
        echo "Total Income: " . $transactions['totals']['income'] . "\n";
        echo "Total Expense: " . $transactions['totals']['expense'] . "\n";
        echo "Total Budget: " . $transactions['totals']['budget'] . "\n";
    }
}

// Helper function to recursively display nested totals
function displayNestedTotals($totals, $prefix = '')
{
    foreach ($totals as $key => $value) {
        if (is_array($value)) {
            // Handle parent-level totals
            if (isset($value[':total'])) {
                echo "{$prefix}- $key: " . $value['total'] . "\n";
            }
            // Recursively display children
            foreach ($value as $childKey => $childValue) {
                if ($childKey !== ':total') {
                    displayNestedTotals([$childKey => $childValue], $prefix . "  ");
                }
            }
        } else {
            // Display single-level category or account
            echo "{$prefix}- $key: $value\n";
        }
    }
}

// Display results
function displayTotals($totals, $json = false)
{

    if ($json) {
        return json_encode($totals, JSON_PRETTY_PRINT);
    } else {

        echo "Total Income: " . $totals['income'] . "\n";
        echo "Total Expense: " . $totals['expense'] . "\n";
        echo "Total Budget: " . $totals['budget'] . "\n\n";

        // Display category totals
        echo "Category Totals:\n";
        displayNestedTotals($totals['category']);
        echo "\n";

        // Display account totals
        echo "Account Totals:\n";
        displayNestedTotals($totals['account']);
        echo "\n";

        // Display person totals
        echo "Person Totals:\n";
        foreach ($totals['person'] as $person => $total) {
            echo "- $person: $total\n";
        }
        echo "\n";

        // Display payment method totals
        echo "Payment Method Totals:\n";
        foreach ($totals['method'] as $method => $total) {
            echo "- $method: $total\n";
        }
        echo "\n";

        // Display budget-specific category totals
        echo "Budget Category Totals:\n";
        displayNestedTotals($totals['budget_category']);
        echo "\n";

        // Display budget-specific person totals
        echo "Budget Person Totals:\n";
        foreach ($totals['budget_person'] as $person => $total) {
            echo "- $person: $total\n";
        }
        echo "\n";

        // Display budget-specific account totals
        echo "Budget Account Totals:\n";
        displayNestedTotals($totals['budget_account']);
        echo "\n";
    }
}

if (php_sapi_name() === 'cli') {
    // Load and parse the .bext file

    $filename = isset($argv[1]) ? $argv[1] : null;
    $filter = isset($argv[2]) ? $argv[2] : null;

    if (file_exists($filename)) {

        $parsed_data = parseBextFile($filename);

        if (!empty($filter)) {
            $results = getTransactionsByFilter($parsed_data, $filter);
            //displayTransactions($results);
            echo displayTransactions($results, 1);
        } else {
            $totals = calculateTotals($parsed_data);
            //displayTotals($totals);
            echo displayTotals($totals, 1);
        }
    } else {
        echo "File not found: {$filename}\n";
    }
}

/* End of file BEXT.php */