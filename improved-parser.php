<?php
$result = '';
$cashBalance = 0;
$cardBalance = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['message'])) {
    $messageText = $_POST['message'];
    
    // Find initial balances
    if (preg_match('/Касса:\s*(\d+)/', $messageText, $matches)) {
        $cashBalance = intval($matches[1]);
    }
    
    if (preg_match('/Карта:\s*(\d+)/', $messageText, $matches)) {
        $cardBalance = intval($matches[1]);
    }
    
    // Process each line
    $lines = explode("\n", $messageText);
    
    foreach ($lines as $line) {
        // Skip empty lines
        if (empty(trim($line))) {
            continue;
        }

        // Process specific transaction patterns
        
        // Pattern: Amount with + prefix
        if (preg_match('/(?:\d{6})?\s*\+\s*(\d+)/', $line, $matches)) {
            $amount = intval($matches[1]);
            
            if (stripos($line, 'на карту') !== false || 
                stripos($line, 'карту') !== false ||
                stripos($line, 'карте') !== false) {
                $cardBalance += $amount;
            } else {
                $cashBalance += $amount;
            }
        }
        
        // Pattern: Amount with - prefix
        elseif (preg_match('/(?:\d{6})?\s*-\s*(\d+)/', $line, $matches) || 
                preg_match('/[-]\s*(\d+)/', $line, $matches)) {
            $amount = intval($matches[1]);
            
            if (stripos($line, 'с карты') !== false || 
                stripos($line, 'карты') !== false || 
                stripos($line, 'на карту') !== false) {
                $cardBalance -= $amount;
            } else {
                $cashBalance -= $amount;
            }
        }
        
        // Pattern: Payment amount with currency
        elseif (preg_match('/(?:\d{6})?\s*[-+]?\s*(\d+)\s*грн/i', $line, $matches)) {
            $amount = intval($matches[1]);
            $isAddition = strpos($line, '+') !== false;
            $isCard = stripos($line, 'карту') !== false || 
                      stripos($line, 'карты') !== false || 
                      stripos($line, 'на карт') !== false;
            
            if ($isAddition) {
                if ($isCard) {
                    $cardBalance += $amount;
                } else {
                    $cashBalance += $amount;
                }
            } else {
                if ($isCard) {
                    $cardBalance -= $amount;
                } else {
                    $cashBalance -= $amount;
                }
            }
        }
        
        // Handle explicit transfers from cash to card
        elseif ((stripos($line, 'перемещение с нала на') !== false || 
                 stripos($line, 'с нала на') !== false) && 
                preg_match('/(\d+)\s*грн/', $line, $matches)) {
            $amount = intval($matches[1]);
            $cashBalance -= $amount;
            $cardBalance += $amount;
        }
        
        // Handle salary payments if not matched by previous patterns
        elseif (stripos($line, 'зп') !== false && preg_match('/(\d+)/', $line, $matches)) {
            $amount = intval($matches[1]);
            
            if (stripos($line, 'карты') !== false || stripos($line, 'карту') !== false) {
                $cardBalance -= $amount;
            } else {
                $cashBalance -= $amount;
            }
        }
        
        // Check for manual balance overrides
        if (preg_match('/касса:?\s*(\d+)/i', $line, $matches) && 
            !preg_match('/^[^,\[]*Касса:/i', $line)) { // Not the initial declaration
            $cashBalance = intval($matches[1]);
        }
        
        if (preg_match('/карта:?\s*(\d+)/i', $line, $matches) && 
            !preg_match('/^[^,\[]*Карта:/i', $line)) { // Not the initial declaration
            $cardBalance = intval($matches[1]);
        }
    }
    
    $result = "Касса: {$cashBalance}\nКарта: {$cardBalance}";
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Калькулятор кассы - Улучшенная версия</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <h1>Калькулятор кассы</h1>
        
        <form method="post">
            <div class="form-group">
                <label for="message">Вставьте текст из Telegram:</label>
                <textarea id="message" name="message" rows="15" required><?php echo isset($_POST['message']) ? htmlspecialchars($_POST['message']) : ''; ?></textarea>
            </div>
            
            <button type="submit">Рассчитать</button>
        </form>
        
        <?php if (!empty($result)): ?>
        <div class="result">
            <h2>Результат:</h2>
            <pre><?php echo htmlspecialchars($result); ?></pre>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
