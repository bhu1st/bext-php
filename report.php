<?php

/**
 * BEXT Financial Reporting Tool
 *
 * This script parses a .bext file and generates a comprehensive HTML report.
 *
 * @copyright Copyright (c) 2024 Bhupal Sapkota
 * @license   http://www.opensource.org/licenses/mit-license.php The MIT License
 * @author    Bhupal Sapkota [www.bhupal.net]
 */

// --- CONFIGURATION ---
$bext_file = 'data.bext'; // CHANGE THIS to the name of your BEXT file.
$currency_symbol = '$';

// --- INITIALIZATION ---
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include the BEXT parser library
require_once 'bext.php';

if (!file_exists($bext_file)) {
    die("<strong>Error:</strong> The BEXT file '{$bext_file}' was not found. Please make sure the file exists and the name is correct in the configuration section of this script.");
}

// Parse the BEXT file and calculate totals
$transactions = parseBextFile($bext_file);
$totals = calculateTotals($transactions);

// --- DATA PREPARATION FOR REPORTS ---

// 1. Budget vs. Expense
$total_budget = $totals['budget'];
$total_expense = $totals['expense'];
$progress_percentage = ($total_budget > 0) ? ($total_expense / $total_budget) * 100 : 0;
if ($progress_percentage > 100) {
    $progress_percentage = 100;
}

// 2. Summary
$total_income = $totals['income'];
$net_savings = $total_income - $total_expense;

// 7. Monthly Category-wise Summary (Pivoted)
$monthly_summary = [];
$all_categories = [];
foreach ($transactions as $tx) {
    if ($tx['type'] === '-') {
        if (!empty($tx['timestamp'])) {
            $date = new DateTime($tx['timestamp']);
            $year_month = $date->format('Y-m');

            foreach ($tx['categories'] as $parent_cat => $sub_cats) {
                // Collect all unique parent categories
                if (!in_array($parent_cat, $all_categories)) {
                    $all_categories[] = $parent_cat;
                }
                // Store expense data as [Month][Category] => Amount
                if (!isset($monthly_summary[$year_month][$parent_cat])) {
                    $monthly_summary[$year_month][$parent_cat] = 0;
                }
                $monthly_summary[$year_month][$parent_cat] += $tx['amount'];
            }
        }
    }
}
// Sort months chronologically
ksort($monthly_summary); 
// Get a sorted list of all unique months for the table header
$all_months = array_keys($monthly_summary);
// Sort categories alphabetically for the table rows
sort($all_categories);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BEXT Financial Report</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background-color: #f4f7f6; color: #333; margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 15px rgba(0,0,0,0.05); }
        h1 { text-align: center; color: #2c3e50; padding-bottom: 10px; }
		h2 { color: #2c3e50; border-bottom: 2px solid #ecf0f1; padding-bottom: 10px; }
        .grid-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; margin-top: 20px; }
        .report-section { background-color: #ffffff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.05); }
        
        /* Progress Bar */
        .progress-section { grid-column: 1 / -1; }
        .progress-bar-container { background-color: #e9ecef; border-radius: 50px; height: 30px; overflow: hidden; }
        .progress-bar { background-color: #3498db; height: 100%; width: <?php echo $progress_percentage; ?>%; text-align: center; line-height: 30px; color: white; font-weight: bold; transition: width 0.5s ease-in-out; }
        .progress-bar.over-budget { background-color: #e74c3c; }
        .progress-labels { display: flex; justify-content: space-between; margin-top: 5px; font-size: 0.9em; font-weight: bold; }
        
        /* Summary */
        .summary-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .summary-item { background-color: #ecf0f1; padding: 15px; border-radius: 5px; text-align: center; }
        .summary-item h3 { margin: 0 0 5px 0; }
        .summary-item p { margin: 0; font-size: 1.5em; font-weight: bold; }
        .income { color: #27ae60; }
        .expense { color: #c0392b; }
        .net { color: #2980b9; }
        .budget { color: #8e44ad; }
        
        /* Tables */
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f2f2f2; font-weight: bold; }
        tr:hover { background-color: #f9f9f9; }
        .text-right { text-align: right; }
        .total-row { font-weight: bold; background-color: #ecf0f1; }
        
        /* Monthly Summary Table */
        .monthly-summary-section { grid-column: 1 / -1; overflow-x: auto; }
		
		/* Footer */
        .report-footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 0.9em;
            color: #777;
        }
        .report-footer p {
            margin: 5px 0;
        }
        .report-footer a {
            color: #3498db;
            text-decoration: none;
        }
        .report-footer a:hover {
            text-decoration: underline;
        }
		
		
    </style>
    <!-- If you need jQuery for future enhancements, uncomment the line below -->
    <!-- <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> -->
</head>
<body>

    <div class="container">
        <h1>Financial Report</h1>

        <!-- 1. Budget vs Expense Progress Bar -->
        <div class="report-section progress-section">
            <h2>Budget vs. Expense</h2>
            <div class="progress-bar-container">
                <div class="progress-bar <?php if($total_expense > $total_budget) echo 'over-budget'; ?>" style="width: <?php echo $progress_percentage; ?>%;">
                    <?php echo round($progress_percentage, 2); ?>%
                </div>
            </div>
            <div class="progress-labels">
                <span>Spent: <?php echo $currency_symbol . number_format($total_expense, 2); ?></span>
                <span>Total Budget: <?php echo $currency_symbol . number_format($total_budget, 2); ?></span>
            </div>
        </div>

		<!-- 2. Summary -->
		<div class="report-section">
			<div class="summary-grid">
				<div class="summary-item">
					<h3>Income</h3>
					<p class="income"><?php echo $currency_symbol . number_format($total_income, 2); ?></p>
				</div>
				<div class="summary-item">
					<h3>Expense</h3>
					<p class="expense"><?php echo $currency_symbol . number_format($total_expense, 2); ?></p>
				</div>
				<div class="summary-item">
					<h3>Net Savings</h3>
					<p class="net"><?php echo $currency_symbol . number_format($net_savings, 2); ?></p>
				</div>
				<div class="summary-item">
					<h3>Budget</h3>
					<p class="budget"><?php echo $currency_symbol . number_format($total_budget, 2); ?></p>
				</div>
			</div>
		</div>
			
        <div class="grid-container">
         
		  <!-- 3. Category Summary -->
            <div class="report-section">
                <h2>Category Summary</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th class="text-right">Income</th>
                            <th class="text-right">Expense</th>
                            <th class="text-right">Budget</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Create a master list of all category keys from both transactions and budgets
                        $category_keys = array_keys($totals['category']);
                        $budget_keys = array_keys($totals['budget_category']);
                        $master_category_list = array_unique(array_merge($category_keys, $budget_keys));
                        sort($master_category_list);

                        foreach ($master_category_list as $cat):
                            $income = 0;
                            $expense = 0;

                            // Get income and expense from transaction totals
                            if (isset($totals['category'][$cat])) {
                                $total = $totals['category'][$cat][':total'];
                                if ($total > 0) {
                                    $income = $total;
                                } else {
                                    $expense = abs($total);
                                }
                            }
                            
                            // Get budget
                            $budget = isset($totals['budget_category'][$cat]) ? $totals['budget_category'][$cat][':total'] : 0;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($cat); ?></td>
                            <td class="text-right income">
                                <?php echo ($income > 0) ? $currency_symbol . number_format($income, 2) : '-'; ?>
                            </td>
                            <td class="text-right expense">
                                <?php echo ($expense > 0) ? $currency_symbol . number_format($expense, 2) : '-'; ?>
                            </td>
                            <td class="text-right budget">
                                <?php echo ($budget > 0) ? $currency_symbol . number_format($budget, 2) : '-'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div>
			
				<!-- 5. Person Summary -->
				<div class="report-section">
					<h2>Person Summary</h2>
					<table>
						<thead>
							<tr>
								<th>Person</th>
								<th class="text-right">Net Balance</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($totals['person'] as $person => $total): ?>
							<tr>
								<td><?php echo htmlspecialchars($person); ?></td>
								<td class="text-right <?php echo ($total >= 0) ? 'income' : 'expense'; ?>">
									<?php echo $currency_symbol . number_format($total, 2); ?>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<!-- 4. Account Summary -->
				<div class="report-section">
					<h2>Account Summary</h2>
					<table>
						<thead>
							<tr>
								<th>Account</th>
								<th class="text-right">Balance</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($totals['account'] as $acc => $data): ?>
							<tr>
								<td><?php echo htmlspecialchars($acc); ?></td>
								<td class="text-right <?php echo ($data[':total'] >= 0) ? 'income' : 'expense'; ?>">
									<?php echo $currency_symbol . number_format($data[':total'], 2); ?>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				
				<!-- 6. Payment Method Summary -->
				<div class="report-section">
					<h2>Payment Method Summary</h2>
					 <table>
						<thead>
							<tr>
								<th>Method</th>
								<th class="text-right">Total Spent</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($totals['method'] as $method => $total): ?>
							<tr>
								<td><?php echo htmlspecialchars($method); ?></td>
								<td class="text-right expense">
									<?php echo $currency_symbol . number_format($total, 2); ?>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>			
			</div>
			
        </div>
        
        <!-- 7. Monthly Category-wise Summary Table (Pivoted View) -->
        <div class="report-section monthly-summary-section">
            <h2>Monthly Category Expense Summary</h2>
            <table>
                <thead>
                    <tr>
                        <th>Category</th>
                        <?php foreach ($all_months as $month): ?>
                            <th class="text-right"><?php echo date("M, Y", strtotime($month)); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_categories as $category): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($category); ?></strong></td>
                        <?php foreach ($all_months as $month): ?>
                            <td class="text-right">
                                <?php 
                                // Look up the expense for the current category and month
                                $amount = isset($monthly_summary[$month][$category]) ? $monthly_summary[$month][$category] : 0;
                                echo ($amount > 0) ? $currency_symbol . number_format($amount, 2) : '-';
                                ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
		
		<!-- Footer -->
        <footer class="report-footer">
            <p>
                Report generated on: <?php echo date('Y-m-d H:i:s'); ?>
            </p>
            <p>
                BEXT Reporting Tool. Copyright &copy; <?php echo date('Y'); ?> 
                <a href="https://github.com/bhu1st/bext-php" target="_blank" rel="noopener">Bhupal Sapkota</a>.
            </p>
        </footer>

    </div>

</body>
</html>