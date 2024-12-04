<?php
/**
 * The .bext file parser.
 *
 * This script provides common functions to parse and process the BEXT file format specification.
 *
 * @copyright Copyright (c) 2024 Bhupal Sapkota
 * @license   http://www.opensource.org/licenses/mit-license.php The MIT License
 * @author    Bhupal Sapkota <www.bhupal.net>
 */

// Parse the .bext file
function parseBextFile($filename)
{
    $data = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $transactions = [];

    
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
        if (preg_match('/^[+\-]\s*(\d+)/', $line, $matches)) {
            $entry['amount'] = (float)$matches[1];
        } elseif (preg_match('/^\$\s*(\d+)/', $line, $matches)) {
            $entry['budget'] = (float)$matches[1];
        }

        // Extract persons
        if (preg_match('/@([^#\[\]~?:]+)/', $line, $matches)) {
            $entry['persons'] = array_map('trim', explode(';', $matches[1]));
        }

        // Extract categories
        if (preg_match('/#([^@\[\]~?:]+)/', $line, $matches)) {
            $entry['categories'] = array_map('trim', explode(';', $matches[1]));
        }

        // Extract timestamp
        if (preg_match('/\[(.*?)\]/', $line, $matches)) {
            $entry['timestamp'] = $matches[1];
        }

        // Extract accounts
        if (preg_match('/~([^#@\[\]?:]+)/', $line, $matches)) {
            $entry['accounts'] = array_map('trim', explode(';', $matches[1]));
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

// Filter parsed data by account, person, category or payment method.
function getTransactionsByFilter($transactions, $filter)
{
    $filteredTransactions = [];
    $totals = [
        'income' => 0,
        'expense' => 0,
        'budget' => 0,
        'category' => [],
        'person' => [],
        'account' => [],
        'method' => [],
    ];

    // Determine the type of filter (person, account, category, or method)
    $filterType = $filter[0]; // Get the prefix
    $filterValue = substr($filter, 1); // Remove the prefix to get the value

   // Date-based filtering
   $dateFilter = false;
   $dateStart = null;
   $dateEnd = null;
   
   if (in_array($filter, ['d', '-d', 'w', '-w', 'm', '-m', 'y', '-y'])) 
   {
        $dateFilter = true; 
        $filterType = $filter; 
        $filterValue = $filter;             
   }

    // Loop through all transactions and filter based on the filter type
    foreach ($transactions as $entry) {
        
        $currentDate = new DateTime();
        $includeTransaction = false;

        switch ($filterType) {

            case 'a': // All Transactions
                $includeTransaction = true;
                break;

            case '~': // Account
                $includeTransaction = in_array($filterValue, $entry['accounts']);
                break;
            case '@': // Person
                $includeTransaction = in_array($filterValue, $entry['persons']);
                break;
            case '#': // Category
                $includeTransaction = in_array($filterValue, $entry['categories']);
                break;
            case ':': // Payment Method
                $includeTransaction = $entry['method'] === $filterValue;
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
            $entryDate = DateTime::createFromFormat('m/d/Y H:i', $entry['timestamp']);
            if ($entryDate && $entryDate >= $dateStart && $entryDate <= $dateEnd) {
                $includeTransaction = true;
            }
        }

        // If transaction matches the filter, add it to the filtered array and calculate totals
        if ($includeTransaction) {
            $filteredTransactions[] = $entry;

            // Calculate totals
            if ($entry['type'] === '+') {
                $totals['income'] += $entry['amount'];
            } elseif ($entry['type'] === '-') {
                $totals['expense'] += $entry['amount'];
            } elseif ($entry['type'] === '$') {
                $totals['budget'] += $entry['budget'];
            }

            // Category totals
            foreach ($entry['categories'] as $category) {
                if (!isset($totals['category'][$category])) {
                    $totals['category'][$category] = 0;
                }
                if ($entry['type'] === '+') {
                    $totals['category'][$category] += $entry['amount'];
                } elseif ($entry['type'] === '-') {
                    $totals['category'][$category] -= $entry['amount'];
                } 
            }

            // Person totals
            foreach ($entry['persons'] as $person) {
                if (!isset($totals['person'][$person])) {
                    $totals['person'][$person] = 0;
                }
                if ($entry['type'] === '+') {
                    $totals['person'][$person] += $entry['amount'];
                } elseif ($entry['type'] === '-') {
                    $totals['person'][$person] -= $entry['amount'];
                } 
            }

            // Account totals
            foreach ($entry['accounts'] as $account) {
                if (!isset($totals['account'][$account])) {
                    $totals['account'][$account] = 0;
                }
                if ($entry['type'] === '+') {
                    $totals['account'][$account] += $entry['amount'];
                } elseif ($entry['type'] === '-') {
                    $totals['account'][$account] -= $entry['amount'];
                } 
            }

            // Payment method totals
            if ($entry['method'] !== 'Other') {
                if (!isset($totals['method'][$entry['method']])) {
                    $totals['method'][$entry['method']] = 0;
                }
                if ($entry['type'] === '+') {
                    $totals['method'][$entry['method']] += $entry['amount'];
                } elseif ($entry['type'] === '-') {
                    $totals['method'][$entry['method']] -= $entry['amount'];
                } 
            }
        }
    }

    return [ 'filter' => $filter, 'transactions' => $filteredTransactions, 'totals' => $totals];
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
        // Calculate totals based on type
        if ($entry['type'] === '+') {
            $totals['income'] += $entry['amount'];
        } elseif ($entry['type'] === '-') {
            $totals['expense'] += $entry['amount'];
        } elseif ($entry['type'] === '$') {
            $totals['budget'] += $entry['budget'];
        }

        // Sum totals by category
        foreach ($entry['categories'] as $category) {
            if (!isset($totals['category'][$category])) {
                $totals['category'][$category] = 0;
            }
            if ($entry['type'] === '+') {
                $totals['category'][$category] += $entry['amount'];
            } elseif ($entry['type'] === '-') {
                $totals['category'][$category] -= $entry['amount'];
            } 
        }

        // Sum totals by person
        foreach ($entry['persons'] as $person) {
            if (!isset($totals['person'][$person])) {
                $totals['person'][$person] = 0;
            }
            if ($entry['type'] === '+') {
                $totals['person'][$person] += $entry['amount'];
            } elseif ($entry['type'] === '-') {
                $totals['person'][$person] -= $entry['amount'];
            } 
        }

        // Sum totals by account
        foreach ($entry['accounts'] as $account) {
            if (!isset($totals['account'][$account])) {
                $totals['account'][$account] = 0;
            }
            if ($entry['type'] === '+') {
                $totals['account'][$account] += $entry['amount'];
            } elseif ($entry['type'] === '-') {
                $totals['account'][$account] -= $entry['amount'];
            } 
        }

        // Sum totals by payment method
        if ($entry['method'] !== 'Other') {
            if (!isset($totals['method'][$entry['method']])) {
                $totals['method'][$entry['method']] = 0;
            }
            if ($entry['type'] === '+') {
                $totals['method'][$entry['method']] += $entry['amount'];
            } elseif ($entry['type'] === '-') {
                $totals['method'][$entry['method']] -= $entry['amount'];
            } 
        }

        // Sum budget totals by category, person, and account
        if ($entry['type'] === '$') {
            // Budget by Category
            foreach ($entry['categories'] as $category) {
                if (!isset($totals['budget_category'][$category])) {
                    $totals['budget_category'][$category] = 0;
                }
                $totals['budget_category'][$category] += $entry['budget'];
            }

            // Budget by Person
            foreach ($entry['persons'] as $person) {
                if (!isset($totals['budget_person'][$person])) {
                    $totals['budget_person'][$person] = 0;
                }
                $totals['budget_person'][$person] += $entry['budget'];
            }

            // Budget by Account
            foreach ($entry['accounts'] as $account) {
                if (!isset($totals['budget_account'][$account])) {
                    $totals['budget_account'][$account] = 0;
                }
                $totals['budget_account'][$account] += $entry['budget'];
            }
        }
    }

    return $totals;
}

function displayTransactions($transactions, $json = false)
{
    if ($json) {
        echo json_encode($transactions, JSON_PRETTY_PRINT);
    } else {
        // Display results
        echo "Transactions for ". $transactions['filter'] . ":\n";
        foreach ($transactions['transactions'] as $entry) {
            echo "-> " . $entry['type'] . " " . $entry['amount'] . " @" . implode(';', $entry['persons']) . " #" . implode(';', $entry['categories']) . " ?" . $entry['remarks'] . "\n";
        }

        echo "\nTotals:\n";
        echo "Total Income: " . $transactions['totals']['income'] . "\n";
        echo "Total Expense: " . $transactions['totals']['expense'] . "\n";
        echo "Total Budget: " . $transactions['totals']['budget'] . "\n";
    }
}

// Display results
function displayTotals($totals, $json = false)
{

    if ($json) {
        echo json_encode($totals, JSON_PRETTY_PRINT);
    } else {

        echo "Total Income: " . $totals['income'] . "\n";
        echo "Total Expense: " . $totals['expense'] . "\n";
        echo "Total Budget: " . $totals['budget'] . "\n\n";
    
        // Display Category Totals
        echo "Category Totals:\n";
        foreach ($totals['category'] as $category => $amount) {
            echo "- $category: $amount\n";
        }
    
        // Display Budget by Category
        echo "\nBudget by Category:\n";
        foreach ($totals['budget_category'] as $category => $budget) {
            echo "- $category: $budget\n";
        }
    
        // Display Person Totals
        echo "\nPerson Totals:\n";
        foreach ($totals['person'] as $person => $amount) {
            echo "- $person: $amount\n";
        }
    
        // Display Budget by Person
        echo "\nBudget by Person:\n";
        foreach ($totals['budget_person'] as $person => $budget) {
            echo "- $person: $budget\n";
        }
    
        // Display Account Totals
        echo "\nAccount Totals:\n";
        foreach ($totals['account'] as $account => $amount) {
            echo "- $account: $amount\n";
        }
    
        // Display Budget by Account
        echo "\nBudget by Account:\n";
        foreach ($totals['budget_account'] as $account => $budget) {
            echo "- $account: $budget\n";
        }
    
        // Display Payment Method Totals
        echo "\nPayment Method Totals:\n";
        foreach ($totals['method'] as $method => $amount) {
            echo "- $method: $amount \n";
        }
    }
}

// Load and parse the .bext file

$filename = isset($argv[1])? $argv[1] : null;
$filter = isset($argv[2]) ? $argv[2] : null;

if (file_exists($filename)) 
{
    
    $parsed_data = parseBextFile($filename);

    if (!empty($filter)) {
        $results = getTransactionsByFilter($parsed_data, $filter);
        //displayTransactions($results);
        displayTransactions($results, 1);
    } else {
        $totals = calculateTotals($parsed_data);
        //displayTotals($totals);
        displayTotals($totals, 1);
    }
} else {
    echo "File not found: {$filename}\n";
}

