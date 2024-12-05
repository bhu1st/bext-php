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
        if (empty($line)) continue;

        $entry = [
            'type' => '',
            'amount' => 0,
            'budget' => 0,
            'persons' => [],
            'categories' => [],
            'timestamp' => null,
            'accounts' => [],
            'remarks' => 'Other',
            'method' => 'Other',
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

        // Extract remarks
        if (preg_match('/\?((?:[^#\[\]~@:])+)/', $line, $matches)) {
            $entry['remarks'] = trim($matches[1]);
        }

        // Match payment method and handle : in the timestamp
        if (preg_match('/:([^\s#\[\]~?:]+)(?=\s|$)/', $line, $matches)) {
            $entry['method'] = trim($matches[1]);
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
        'budget_account' => [],
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
                    $totals['category'][$parent][$child] += $amount;
                    $totals['category'][$parent][':total'] += $amount;
                }
            } else {
                $totals['category'][$parent][':total'] += $amount;
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
                $totals['account'][$parent] = ['total' => 0];
            }
            if (is_array($children)) {
                foreach ($children as $child => $_) {
                    if (!isset($totals['account'][$parent][$child])) {
                        $totals['account'][$parent][$child] = 0;
                    }
                    $totals['account'][$parent][$child] += $amount;
                }
            } else {
                $totals['account'][$parent]['total'] += $amount;
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
            if ($type === '+') {
                $totals['method'][$entry['method']] += $amount;
            } elseif ($type === '-') {
                $totals['method'][$entry['method']] -= $amount;
            }
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

function matchNestedCategoryOrAccount($data, $query)
{
    $parts = explode('>', $query); // Split query by '>'
    $key = $parts[0];
    $subkey = $parts[1] ?? null;

    // Check if the parent key matches
    if (!isset($data[$key])) {
        return false;
    }

    // Check for subkey match
    if ($subkey !== null) {
        return isset($data[$key][$subkey]);
    }

    // If no subkey, match the parent category/account
    return true;
}

// Filter parsed data by account, person, category or payment method.
function getTransactionsByFilter($transactions, $filter)
{
    $filteredTransactions = [];

    // Determine the type of filter (person, account, category, or method)
    $type = $filter[0]; // Get the prefix
    $query = substr($filter, 1); // Remove the prefix to get the value

    // Date-based filtering
    $dateFilter = false;
    $dateStart = null;
    $dateEnd = null;

    if (in_array($filter, ['d', '-d', 'w', '-w', 'm', '-m', 'y', '-y'])) {
        $dateFilter = true;
        $type = $filter;
        $query = $filter;
    }

    // Loop through all transactions and filter based on the filter type
    foreach ($transactions as $entry) {

        $currentDate = new DateTime();
        $matched = false;

        switch ($type) {

            case 'a': // All Transactions
                $matched = true;
                break;

            case '~': // Account
                $matched = matchNestedCategoryOrAccount($entry['accounts'], $query);
                break;
            case '@': // Person
                $matched = in_array($query, $entry['persons']);
                break;
            case '#': // Category
                $matched = matchNestedCategoryOrAccount($entry['categories'], $query);
                break;
            case ':': // Payment Method
                $matched = $entry['method'] === $query;
                break;

            case 'd': // Today
                $dateStart = $currentDate->setTime(0, 0, 0);
                $dateEnd = clone $dateStart;
                $dateEnd->setTime(23, 59, 59);
                break;

            case '-d': // Yesterday
                $dateStart = $currentDate->modify('-1 day')->setTime(0, 0, 0);
                $dateEnd = clone $dateStart;
                $dateEnd->setTime(23, 59, 59);
                break;

            case 'w': // Current week
                $dateStart = $currentDate->modify('this week')->setTime(0, 0, 0);
                $dateEnd = clone $dateStart;
                $dateEnd->modify('+6 days')->setTime(23, 59, 59);
                break;

            case '-w': // Last week
                $dateStart = $currentDate->modify('last week')->setTime(0, 0, 0);
                $dateEnd = clone $dateStart;
                $dateEnd->modify('+6 days')->setTime(23, 59, 59);
                break;

            case 'm': // Current month
                $dateStart = $currentDate->modify('first day of this month')->setTime(0, 0, 0);
                $dateEnd = clone $dateStart;
                $dateEnd->modify('last day of this month')->setTime(23, 59, 59);
                break;

            case '-m': // Last month
                $dateStart = $currentDate->modify('first day of last month')->setTime(0, 0, 0);
                $dateEnd = clone $dateStart;
                $dateEnd = $dateEnd->modify('last day of this month')->setTime(23, 59, 59);
                break;

            case 'y': // Current year
                $dateStart = $currentDate->setDate($currentDate->format('Y'), 1, 1)->setTime(0, 0, 0);
                $dateEnd = clone $dateStart;
                $dateEnd->setDate($currentDate->format('Y'), 12, 31)->setTime(23, 59, 59);
                break;

            case '-y': // Last year
                $dateStart = $currentDate->modify('-1 year')->setDate($currentDate->format('Y'), 1, 1)->setTime(0, 0, 0);
                $dateEnd = clone $dateStart;
                $dateEnd = $dateEnd->setDate($dateEnd->format('Y'), 12, 31)->setTime(23, 59, 59);
                break;
        }

        // Filter by date range
        if ($dateFilter && $entry['timestamp'] !== null) {
            $entryDate = DateTime::createFromFormat('Y/m/d H:i', date("Y/m/d H:i", strtotime($entry['timestamp'])));
            if ($entryDate && $entryDate >= $dateStart && $entryDate <= $dateEnd) {
                $matched = true;
            }
        }

        // If transaction matches the filter, add it to the filtered array and calculate totals
        if ($matched) {
            $filteredTransactions[] = $entry;
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