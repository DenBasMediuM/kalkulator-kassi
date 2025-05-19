<?php
$result = '';
$cashBalance = 0;
$cardBalance = 0;
$step = 1;
$transactions = [];

// Function to determine the likely transaction type based on text analysis
function determineTransactionType($text, $amount) {
    $lowerText = mb_strtolower($text);
    
    // Check for transfers between cash and card
    if (preg_match('/перемещение.*с.*кассы.*на.*карт/i', $lowerText) || 
        preg_match('/с.*нала.*на.*карт/i', $lowerText) ||
        preg_match('/переводим.*на.*карт/i', $lowerText)) {
        return 'transfer_to_card';
    }
    
    if (preg_match('/перемещение.*с.*карты.*на.*касс/i', $lowerText) || 
        preg_match('/с.*карты.*на.*касс/i', $lowerText)) {
        return 'transfer_to_cash';
    }
    
    // Check explicit indicators for card transactions
    $isCard = (strpos($lowerText, 'карту') !== false || 
              strpos($lowerText, 'карта') !== false || 
              strpos($lowerText, 'на карт') !== false);
    
    // Check explicit sign indicators
    if (strpos($lowerText, '+') !== false) {
        return $isCard ? 'card_plus' : 'cash_plus';
    }
    
    if (strpos($lowerText, '-') !== false || preg_match('/^[-–—]\s*\d+/i', $lowerText)) {
        return $isCard ? 'card_minus' : 'cash_minus';
    }
    
    // Analyze other patterns
    if (preg_match('/предоплата|\bпре\b|оплат/i', $lowerText)) {
        return $isCard ? 'card_plus' : 'cash_plus';
    }
    
    if (preg_match('/зп|аванс|зарплат|уборка|взяли?|взял/i', $lowerText)) {
        return 'cash_minus'; // Typically expenses from cash
    }
    
    if (preg_match('/з\.?ч\.?|запчаст|франшиз|подписк/i', $lowerText)) {
        // Components and subscriptions are typically expenses
        return $isCard ? 'card_minus' : 'cash_minus';
    }
    
    // Default based on content hints
    if (preg_match('/возврат|вернул|обратно/i', $lowerText)) {
        return 'cash_plus'; // Typically returns are cash additions
    }
    
    // By default, assume it's an addition to cash unless mentioned otherwise
    return 'cash_plus';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if we're returning from Step 3 with saved selections
    if (isset($_POST['saved_transaction']) && !empty($_POST['saved_transaction'])) {
        $step = 2;
        $transactions = [];
        $cashBalance = isset($_POST['initial_cash']) ? intval($_POST['initial_cash']) : 0;
        $cardBalance = isset($_POST['initial_card']) ? intval($_POST['initial_card']) : 0;
        
        // Restore transactions from saved data
        foreach ($_POST['saved_transaction'] as $index => $transactionData) {
            $transactions[] = json_decode($transactionData, true);
        }
    }
    // Step 1 - Extract transactions
    else if (isset($_POST['message']) && !empty($_POST['message'])) {
        $step = 2;
        $messageText = $_POST['message'];
        
        // Find initial cash balance
        if (preg_match('/Касса:\s*(\d+)/', $messageText, $matches)) {
            $cashBalance = intval($matches[1]);
        }
        
        // Find initial card balance
        if (preg_match('/Карта:\s*(\d+)/', $messageText, $matches)) {
            $cardBalance = intval($matches[1]);
        }
        
        // Process each line to extract transactions
        $lines = explode("\n", $messageText);
        
        foreach ($lines as $lineNum => $line) {
            // Skip empty lines
            if (empty(trim($line))) {
                continue;
            }
            
            // Extract all numbers from the line
            if (preg_match_all('/\b(\d{3,})\b/', $line, $matches)) {
                // Skip if this appears to be a date line (matches patterns like [16.05.2025 10:31])
                if (preg_match('/\[\d{2}\.\d{2}\.\d{4}\s+\d{1,2}:\d{2}\]/', $line)) {
                    continue;
                }
                
                foreach ($matches[1] as $index => $amount) {
                    // Skip numbers that look like order IDs
                    if (strlen($amount) == 6 && strpos($line, $amount) !== false && 
                        preg_match('/\b'.$amount.'\b/', $line)) {
                        continue;
                    }
                    
                    // Create a new transaction entry
                    $transactions[] = [
                        'line_number' => $lineNum + 1,
                        'text' => $line,
                        'amount' => $amount,
                        'auto_type' => determineTransactionType($line, $amount) // Add auto-detected type
                    ];
                }
            }
            
            // Look for manual balance declarations and add them with a special flag
            if (preg_match('/касса:?\s*(\d+)/i', $line, $matches) && 
                !preg_match('/^[^,\[]*Касса:/i', $line)) {
                $transactions[] = [
                    'line_number' => $lineNum + 1,
                    'text' => $line,
                    'amount' => $matches[1],
                    'special' => 'cash_override',
                ];
            }
            
            if (preg_match('/карта:?\s*(\d+)/i', $line, $matches) && 
                !preg_match('/^[^,\[]*Карта:/i', $line)) {
                $transactions[] = [
                    'line_number' => $lineNum + 1,
                    'text' => $line,
                    'amount' => $matches[1],
                    'special' => 'card_override',
                ];
            }
        }
    }
    
    // Step 2 - Process selected transactions
    if (isset($_POST['calculate']) && !empty($_POST['transaction'])) {
        $step = 3;
        $cashBalance = isset($_POST['initial_cash']) ? intval($_POST['initial_cash']) : 0;
        $cardBalance = isset($_POST['initial_card']) ? intval($_POST['initial_card']) : 0;
        
        foreach ($_POST['transaction'] as $index => $transactionData) {
            $data = json_decode($transactionData, true);
            $type = $_POST['type'][$index];
            $amount = intval($data['amount']);
            
            if (isset($data['special'])) {
                // Handle special cases like balance overrides
                if ($data['special'] === 'cash_override') {
                    $cashBalance = $amount;
                } else if ($data['special'] === 'card_override') {
                    $cardBalance = $amount;
                }
            } else {
                // Regular transaction
                if ($type === 'cash_plus') {
                    $cashBalance += $amount;
                } else if ($type === 'cash_minus') {
                    $cashBalance -= $amount;
                } else if ($type === 'card_plus') {
                    $cardBalance += $amount;
                } else if ($type === 'card_minus') {
                    $cardBalance -= $amount;
                } else if ($type === 'transfer_to_card') {
                    $cashBalance -= $amount;
                    $cardBalance += $amount;
                } else if ($type === 'transfer_to_cash') {
                    $cardBalance -= $amount;
                    $cashBalance += $amount;
                } else if ($type === 'ignore') {
                    // Skip this transaction
                }
            }
        }
        
        $totalBalance = $cashBalance + $cardBalance;
        $result = "Касса: {$cashBalance}\nКарта: {$cardBalance}\nИтого: {$totalBalance}";
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Калькулятор кассы</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .container {
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1 {
            text-align: center;
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }
        textarea {
            width: 100%;
            padding: 8px;
            height: 300px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        button {
            background-color: #4CAF50;
            color: white;
            border: none;
            padding: 10px 20px;
            cursor: pointer;
            border-radius: 4px;
        }
        button:hover {
            background-color: #45a049;
        }
        .transaction {
            margin-bottom: 15px;
            padding: 10px;
            background-color: #f1f1f1;
            border-left: 3px solid #ccc;
        }
        .transaction pre {
            margin: 0;
            white-space: pre-wrap;
            font-family: monospace;
        }
        .transaction-options {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .transaction-options label {
            display: inline-flex;
            align-items: center;
            margin: 0;
            font-weight: normal;
        }
        .result {
            margin-top: 20px;
            padding: 15px;
            background-color: #e8f5e9;
            border-left: 5px solid #4CAF50;
        }
        .result pre {
            margin: 0;
            font-family: monospace;
            font-size: 18px;
        }
        .result-details {
            font-family: monospace;
            font-size: 18px;
            white-space: pre;
            line-height: 1.8;
        }
        .result-row {
            display: block;
        }
        .result-row.total {
            margin-top: 5px;
            padding-top: 5px;
            border-top: 1px solid rgba(0,0,0,0.1);
            font-weight: bold;
        }
        .result-value {
            font-weight: bold;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #f2f2f2;
        }
        .line-number {
            width: 50px;
            text-align: center;
        }
        .amount {
            width: 100px;
        }
        .options {
            width: 300px;
        }
        .action-options {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed #ddd;
        }
        .radio-group {
            display: flex;
            flex-direction: row;
            gap: 12px;
            margin-top: 5px;
        }
        .radio-button {
            position: relative;
            display: inline-block;
        }
        .radio-button input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }
        .radio-button label {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 40px;
            height: 40px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 18px;
            margin: 0;
            font-weight: normal;
            background-color: #f1f1f1;
            border: 2px solid #ddd;
            transition: all 0.2s ease;
        }
        .radio-button input[type="radio"]:checked + label {
            background-color: #e3f2fd;
            border-color: #2196F3;
            box-shadow: 0 0 5px rgba(33, 150, 243, 0.5);
        }
        .radio-button[data-type="cash_plus"] label {
            color: #4CAF50;
        }
        .radio-button[data-type="cash_minus"] label {
            color: #F44336;
        }
        .radio-button[data-type="card_plus"] label {
            color: #2196F3;
        }
        .radio-button[data-type="card_minus"] label {
            color: #9C27B0;
        }
        .radio-button[data-type="transfer_to_card"] label {
            color: #FF9800;
        }
        .radio-button[data-type="transfer_to_cash"] label {
            color: #009688;
        }
        .radio-button[data-type="ignore"] label {
            color: #757575;
        }
        .tooltip {
            position: relative;
        }
        .tooltip:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            padding: 5px 10px;
            border-radius: 4px;
            background: rgba(0,0,0,0.8);
            color: white;
            font-size: 12px;
            white-space: nowrap;
            z-index: 10;
        }
        pre {
            margin: 0 0 10px;
            white-space: pre-wrap;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Калькулятор кассы</h1>
        
        <?php if ($step == 1): ?>
        <!-- Step 1: Enter the Telegram conversation -->
        <form method="post">
            <div>
                <label for="message">Вставьте текст из Telegram:</label>
                <textarea id="message" name="message" required><?php echo isset($_POST['message']) ? htmlspecialchars($_POST['message']) : ''; ?></textarea>
            </div>
            
            <button type="submit">Извлечь транзакции</button>
        </form>
        
        <?php elseif ($step == 2): ?>
        <!-- Step 2: Mark transactions as cash/card and plus/minus -->
        <form method="post">
            <input type="hidden" name="initial_cash" value="<?php echo $cashBalance; ?>">
            <input type="hidden" name="initial_card" value="<?php echo $cardBalance; ?>">
            <input type="hidden" name="message_original" value="<?php echo isset($_POST['message_original']) ? htmlspecialchars($_POST['message_original']) : htmlspecialchars($_POST['message']); ?>">
            
            <div style="margin-bottom: 20px;">
                <h2>Начальные балансы:</h2>
                <p>Касса: <?php echo $cashBalance; ?> | Карта: <?php echo $cardBalance; ?></p>
            </div>
            
            <h2>Отметьте тип каждой транзакции:</h2>
            
            <table>
                <thead>
                    <tr>
                        <th class="line-number">№</th>
                        <th>Текст</th>
                        <th class="amount">Сумма</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($transactions as $index => $transaction): ?>
                    <tr>
                        <td class="line-number"><?php echo $transaction['line_number']; ?></td>
                        <td>
                            <pre><?php echo htmlspecialchars($transaction['text']); ?></pre>
                            
                            <input type="hidden" name="transaction[<?php echo $index; ?>]" 
                                   value='<?php echo htmlspecialchars(json_encode($transaction)); ?>'>
                            
                            <div class="action-options">
                                <?php if (isset($transaction['special'])): ?>
                                    <?php if ($transaction['special'] === 'cash_override'): ?>
                                        <span class="special-action">Установка значения кассы</span>
                                        <input type="hidden" name="type[<?php echo $index; ?>]" value="cash_override">
                                    <?php elseif ($transaction['special'] === 'card_override'): ?>
                                        <span class="special-action">Установка значения карты</span>
                                        <input type="hidden" name="type[<?php echo $index; ?>]" value="card_override">
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="radio-group">
                                        <div class="radio-button tooltip" data-type="cash_plus" data-tooltip="+ Касса (приход в кассу)">
                                            <input type="radio" name="type[<?php echo $index; ?>]" id="cash_plus_<?php echo $index; ?>" value="cash_plus" required
                                                <?php if (isset($_POST['saved_type'][$index])) {
                                                    echo $_POST['saved_type'][$index] == 'cash_plus' ? 'checked' : '';
                                                } else {
                                                    echo isset($transaction['auto_type']) && $transaction['auto_type'] == 'cash_plus' ? 'checked' : '';
                                                } ?>>
                                            <label for="cash_plus_<?php echo $index; ?>">💰+</label>
                                        </div>
                                        
                                        <div class="radio-button tooltip" data-type="cash_minus" data-tooltip="- Касса (расход из кассы)">
                                            <input type="radio" name="type[<?php echo $index; ?>]" id="cash_minus_<?php echo $index; ?>" value="cash_minus"
                                                <?php if (isset($_POST['saved_type'][$index])) {
                                                    echo $_POST['saved_type'][$index] == 'cash_minus' ? 'checked' : '';
                                                } else {
                                                    echo isset($transaction['auto_type']) && $transaction['auto_type'] == 'cash_minus' ? 'checked' : '';
                                                } ?>>
                                            <label for="cash_minus_<?php echo $index; ?>">💰-</label>
                                        </div>
                                        
                                        <div class="radio-button tooltip" data-type="card_plus" data-tooltip="+ Карта (приход на карту)">
                                            <input type="radio" name="type[<?php echo $index; ?>]" id="card_plus_<?php echo $index; ?>" value="card_plus"
                                                <?php if (isset($_POST['saved_type'][$index])) {
                                                    echo $_POST['saved_type'][$index] == 'card_plus' ? 'checked' : '';
                                                } else {
                                                    echo isset($transaction['auto_type']) && $transaction['auto_type'] == 'card_plus' ? 'checked' : '';
                                                } ?>>
                                            <label for="card_plus_<?php echo $index; ?>">💳+</label>
                                        </div>
                                        
                                        <div class="radio-button tooltip" data-type="card_minus" data-tooltip="- Карта (расход с карты)">
                                            <input type="radio" name="type[<?php echo $index; ?>]" id="card_minus_<?php echo $index; ?>" value="card_minus"
                                                <?php if (isset($_POST['saved_type'][$index])) {
                                                    echo $_POST['saved_type'][$index] == 'card_minus' ? 'checked' : '';
                                                } else {
                                                    echo isset($transaction['auto_type']) && $transaction['auto_type'] == 'card_minus' ? 'checked' : '';
                                                } ?>>
                                            <label for="card_minus_<?php echo $index; ?>">💳-</label>
                                        </div>
                                        
                                        <div class="radio-button tooltip" data-type="transfer_to_card" data-tooltip="Перемещение с кассы на карту">
                                            <input type="radio" name="type[<?php echo $index; ?>]" id="transfer_to_card_<?php echo $index; ?>" value="transfer_to_card"
                                                <?php if (isset($_POST['saved_type'][$index])) {
                                                    echo $_POST['saved_type'][$index] == 'transfer_to_card' ? 'checked' : '';
                                                } else {
                                                    echo isset($transaction['auto_type']) && $transaction['auto_type'] == 'transfer_to_card' ? 'checked' : '';
                                                } ?>>
                                            <label for="transfer_to_card_<?php echo $index; ?>">💰→💳</label>
                                        </div>
                                        
                                        <div class="radio-button tooltip" data-type="transfer_to_cash" data-tooltip="Перемещение с карты в кассу">
                                            <input type="radio" name="type[<?php echo $index; ?>]" id="transfer_to_cash_<?php echo $index; ?>" value="transfer_to_cash"
                                                <?php if (isset($_POST['saved_type'][$index])) {
                                                    echo $_POST['saved_type'][$index] == 'transfer_to_cash' ? 'checked' : '';
                                                } else {
                                                    echo isset($transaction['auto_type']) && $transaction['auto_type'] == 'transfer_to_cash' ? 'checked' : '';
                                                } ?>>
                                            <label for="transfer_to_cash_<?php echo $index; ?>">💳→💰</label>
                                        </div>
                                        
                                        <div class="radio-button tooltip" data-type="ignore" data-tooltip="Игнорировать эту транзакцию">
                                            <input type="radio" name="type[<?php echo $index; ?>]" id="ignore_<?php echo $index; ?>" value="ignore"
                                                <?php if (isset($_POST['saved_type'][$index])) {
                                                    echo $_POST['saved_type'][$index] == 'ignore' ? 'checked' : '';
                                                } else {
                                                    echo isset($transaction['auto_type']) && $transaction['auto_type'] == 'ignore' ? 'checked' : '';
                                                } ?>>
                                            <label for="ignore_<?php echo $index; ?>">❌</label>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="amount"><?php echo $transaction['amount']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            
            <button type="submit" name="calculate" value="1">Рассчитать</button>
        </form>
        
        <?php elseif ($step == 3): ?>
        <!-- Step 3: Show the calculation result -->
        <div class="result">
            <h2>Результат:</h2>
            <?php 
                // Parse the result string to show values with better formatting
                $resultLines = explode("\n", $result);
                $cashValue = intval(str_replace('Касса: ', '', $resultLines[0]));
                $cardValue = intval(str_replace('Карта: ', '', $resultLines[1]));
                $totalValue = intval(str_replace('Итого: ', '', $resultLines[2]));
            ?>
            <pre class="result-details">Касса: <?php echo number_format($cashValue, 0, '.', ''); ?> 
Карта: <?php echo number_format($cardValue, 0, '.', ''); ?> 
Итого: <?php echo number_format($totalValue, 0, '.', ''); ?> грн</pre>
        </div>
        
        <h3>Детали расчёта:</h3>
        
        <table>
            <thead>
                <tr>
                    <th class="line-number">№</th>
                    <th>Текст</th>
                    <th class="amount">Сумма</th>
                    <th>Действие</th>
                </tr>
            </thead>
            <tbody>
            <?php 
                foreach ($_POST['transaction'] as $index => $transactionData) {
                    $transaction = json_decode($transactionData, true);
                    $type = $_POST['type'][$index];
            ?>
                <tr>
                    <td class="line-number"><?php echo $transaction['line_number']; ?></td>
                    <td><pre><?php echo htmlspecialchars($transaction['text']); ?></pre></td>
                    <td class="amount"><?php echo $transaction['amount']; ?></td>
                    <td>
                        <?php 
                        if (isset($transaction['special'])) {
                            if ($transaction['special'] === 'cash_override') {
                                echo '<span class="special-action">Установка значения кассы</span>';
                            } elseif ($transaction['special'] === 'card_override') {
                                echo '<span class="special-action">Установка значения карты</span>';
                            }
                        } else {
                            switch ($type) {
                                case 'cash_plus':
                                    echo '💰+ (Приход в кассу)';
                                    break;
                                case 'cash_minus':
                                    echo '💰- (Расход из кассы)';
                                    break;
                                case 'card_plus':
                                    echo '💳+ (Приход на карту)';
                                    break;
                                case 'card_minus':
                                    echo '💳- (Расход с карты)';
                                    break;
                                case 'transfer_to_card':
                                    echo '💰→💳 (Перемещение с кассы на карту)';
                                    break;
                                case 'transfer_to_cash':
                                    echo '💳→💰 (Перемещение с карты в кассу)';
                                    break;
                                case 'ignore':
                                    echo '❌ (Игнорировать)';
                                    break;
                            }
                        }
                        ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        
        <div style="display: flex; gap: 10px; margin-top: 20px;">
            <form method="post">
                <input type="hidden" name="message" value="<?php echo isset($_POST['message_original']) ? htmlspecialchars($_POST['message_original']) : htmlspecialchars($_POST['message']); ?>">
                
                <!-- Store previous selections to preserve them when editing -->
                <?php foreach ($_POST['transaction'] as $index => $transactionData): ?>
                    <input type="hidden" name="saved_transaction[<?php echo $index; ?>]" value="<?php echo htmlspecialchars($transactionData); ?>">
                    <input type="hidden" name="saved_type[<?php echo $index; ?>]" value="<?php echo htmlspecialchars($_POST['type'][$index]); ?>">
                <?php endforeach; ?>
                
                <!-- Pass the initial balances -->
                <input type="hidden" name="initial_cash" value="<?php echo isset($_POST['initial_cash']) ? htmlspecialchars($_POST['initial_cash']) : '0'; ?>">
                <input type="hidden" name="initial_card" value="<?php echo isset($_POST['initial_card']) ? htmlspecialchars($_POST['initial_card']) : '0'; ?>">
                
                <button type="submit" style="background-color: #2196F3;">Изменить разметку</button>
            </form>
            
            <form method="post">
                <button type="submit">Начать заново</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>