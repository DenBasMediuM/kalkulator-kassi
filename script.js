document.addEventListener('DOMContentLoaded', () => {
    const step1 = document.getElementById('step1');
    const step2 = document.getElementById('step2');
    const step3 = document.getElementById('step3');

    const extractForm = document.getElementById('extractForm');
    const transactionForm = document.getElementById('transactionForm');
    const backToEditButton = document.getElementById('backToEditButton');
    const startOverButton = document.getElementById('startOverButton');

    let transactions = [];
    let initialCash = 0;
    let initialCard = 0;

    function showStep(step) {
        step1.style.display = 'none';
        step2.style.display = 'none';
        step3.style.display = 'none';
        document.getElementById(`step${step}`).style.display = 'block';
    }

    function determineTransactionType(text) {
        const lowerText = text.toLowerCase();

        if (/перемещение.*с.*кассы.*на.*карт/i.test(lowerText) ||
            /с.*нала.*на.*карт/i.test(lowerText) ||
            /переводим.*на.*карт/i.test(lowerText)) {
            return 'transfer_to_card';
        }

        if (/перемещение.*с.*карты.*на.*касс/i.test(lowerText) ||
            /с.*карты.*на.*касс/i.test(lowerText)) {
            return 'transfer_to_cash';
        }

        const isCard = /карту|карта|на карт/i.test(lowerText);

        if (lowerText.includes('+')) {
            return isCard ? 'card_plus' : 'cash_plus';
        }

        if (lowerText.includes('-') || /^[-–—]\s*\d+/i.test(lowerText)) {
            return isCard ? 'card_minus' : 'cash_minus';
        }

        if (/предоплата|\bпре\b|оплат/i.test(lowerText)) {
            return isCard ? 'card_plus' : 'cash_plus';
        }

        if (/зп|аванс|зарплат|уборка|взяли?|взял/i.test(lowerText)) {
            return 'cash_minus';
        }

        if (/з\.?ч\.?|запчаст|франшиз|подписк/i.test(lowerText)) {
            return isCard ? 'card_minus' : 'cash_minus';
        }

        if (/возврат|вернул|обратно/i.test(lowerText)) {
            return 'cash_plus';
        }

        return 'cash_plus';
    }

    extractForm.addEventListener('submit', (e) => {
        e.preventDefault();
        transactions = [];
        const messageText = document.getElementById('message').value;

        let cashMatch = messageText.match(/Касса:\s*(\d+)/);
        initialCash = cashMatch ? parseInt(cashMatch[1], 10) : 0;

        let cardMatch = messageText.match(/Карта:\s*(\d+)/);
        initialCard = cardMatch ? parseInt(cardMatch[1], 10) : 0;

        document.getElementById('initialBalances').innerHTML = `Касса: ${initialCash} | Карта: ${initialCard}`;

        const lines = messageText.split('\n');
        lines.forEach((originalLine, lineNum) => {
            let line = originalLine.replace(/^\[\d{2}\.\d{2}\.\d{4}\s+\d{1,2}:\d{2}\]\s*[^:]+:\s*/, '');

            if (!line.trim()) {
                return;
            }

            const numbers = line.match(/\b(\d+)\b/g);
            if (numbers) {
                if (/\[\d{2}\.\d{2}\.\d{4}\s+\d{1,2}:\d{2}\]/.test(line)) {
                    return;
                }

                numbers.forEach(amountStr => {
                    const amount = parseInt(amountStr, 10);
                    if (amountStr.length === 6) { // Likely an order ID
                        return;
                    }
                    if (amount < 10) {
                        return;
                    }

                    transactions.push({
                        line_number: lineNum + 1,
                        text: line,
                        amount: amount,
                        auto_type: determineTransactionType(line)
                    });
                });
            }
            
            let cashOverrideMatch = line.match(/касса:?\s*(\d+)/i);
            if (cashOverrideMatch && !/^[^,\[]*Касса:/i.test(line)) {
                 transactions.push({
                    line_number: lineNum + 1,
                    text: line,
                    amount: parseInt(cashOverrideMatch[1], 10),
                    special: 'cash_override',
                });
            }
            
            let cardOverrideMatch = line.match(/карта:?\s*(\d+)/i);
            if (cardOverrideMatch && !/^[^,\[]*Карта:/i.test(line)) {
                 transactions.push({
                    line_number: lineNum + 1,
                    text: line,
                    amount: parseInt(cardOverrideMatch[1], 10),
                    special: 'card_override',
                });
            }
        });

        renderTransactionRows();
        showStep(2);
    });

    function renderTransactionRows() {
        const tbody = document.getElementById('transactionsBody');
        tbody.innerHTML = '';
        transactions.forEach((transaction, index) => {
            const row = document.createElement('tr');
            
            let optionsHtml = '';
            if (transaction.special) {
                 if (transaction.special === 'cash_override') {
                    optionsHtml = `<span class="special-action">Установка значения кассы</span><input type="hidden" name="type_${index}" value="cash_override">`;
                } else if (transaction.special === 'card_override') {
                    optionsHtml = `<span class="special-action">Установка значения карты</span><input type="hidden" name="type_${index}" value="card_override">`;
                }
            } else {
                const types = [
                    {id: 'cash_plus', label: '💰+', tooltip: '+ Касса (приход в кассу)'},
                    {id: 'cash_minus', label: '💰-', tooltip: '- Касса (расход из кассы)'},
                    {id: 'card_plus', label: '💳+', tooltip: '+ Карта (приход на карту)'},
                    {id: 'card_minus', label: '💳-', tooltip: '- Карта (расход с карты)'},
                    {id: 'transfer_to_card', label: '💰→💳', tooltip: 'Перемещение с кассы на карту'},
                    {id: 'transfer_to_cash', label: '💳→💰', tooltip: 'Перемещение с карты в кассу'},
                    {id: 'ignore', label: '❌', tooltip: 'Игнорировать эту транзакцию'}
                ];
                optionsHtml = `<div class="radio-group">` + types.map(type => `
                    <div class="radio-button tooltip" data-type="${type.id}" data-tooltip="${type.tooltip}">
                        <input type="radio" name="type_${index}" id="${type.id}_${index}" value="${type.id}" 
                            ${transaction.auto_type === type.id ? 'checked' : ''} required>
                        <label for="${type.id}_${index}">${type.label}</label>
                    </div>
                `).join('') + `</div>`;
            }

            row.innerHTML = `
                <td class="line-number">${transaction.line_number}</td>
                <td>
                    <pre>${transaction.text}</pre>
                    <div class="action-options">${optionsHtml}</div>
                </td>
                <td class="amount">${transaction.amount}</td>
            `;
            tbody.appendChild(row);
        });
    }

    transactionForm.addEventListener('submit', (e) => {
        e.preventDefault();
        let cashBalance = initialCash;
        let cardBalance = initialCard;
        
        const detailsBody = document.getElementById('calculationDetails');
        detailsBody.innerHTML = '';

        transactions.forEach((transaction, index) => {
            const selectedType = document.querySelector(`input[name="type_${index}"]:checked`).value;
            transaction.selected_type = selectedType; // Save for "edit"
            const amount = transaction.amount;

            if (transaction.special) {
                if (transaction.special === 'cash_override') cashBalance = amount;
                if (transaction.special === 'card_override') cardBalance = amount;
            } else {
                switch (selectedType) {
                    case 'cash_plus': cashBalance += amount; break;
                    case 'cash_minus': cashBalance -= amount; break;
                    case 'card_plus': cardBalance += amount; break;
                    case 'card_minus': cardBalance -= amount; break;
                    case 'transfer_to_card':
                        cashBalance -= amount;
                        cardBalance += amount;
                        break;
                    case 'transfer_to_cash':
                        cardBalance -= amount;
                        cashBalance += amount;
                        break;
                    case 'ignore': break;
                }
            }
            
            // Render details table row
            const row = document.createElement('tr');
            let actionText = '';
             if (transaction.special) {
                if (transaction.special === 'cash_override') actionText = '<span class="special-action">Установка значения кассы</span>';
                if (transaction.special === 'card_override') actionText = '<span class="special-action">Установка значения карты</span>';
            } else {
                 switch (selectedType) {
                    case 'cash_plus': actionText = '💰+ (Приход в кассу)'; break;
                    case 'cash_minus': actionText = '💰- (Расход из кассы)'; break;
                    case 'card_plus': actionText = '💳+ (Приход на карту)'; break;
                    case 'card_minus': actionText = '💳- (Расход с карты)'; break;
                    case 'transfer_to_card': actionText = '💰→💳 (Перемещение с кассы на карту)'; break;
                    case 'transfer_to_cash': actionText = '💳→💰 (Перемещение с карты в кассу)'; break;
                    case 'ignore': actionText = '❌ (Игнорировать)'; break;
                }
            }
            row.innerHTML = `
                <td class="line-number">${transaction.line_number}</td>
                <td><pre>${transaction.text}</pre></td>
                <td class="amount">${transaction.amount}</td>
                <td>${actionText}</td>
            `;
            detailsBody.appendChild(row);
        });

        const totalBalance = cashBalance + cardBalance;
        document.getElementById('resultDetails').innerHTML = `Касса: ${cashBalance}
Карта: ${cardBalance}
Итого: ${totalBalance} грн`;

        showStep(3);
    });
    
    backToEditButton.addEventListener('click', () => {
        // Restore selections
        transactions.forEach(t => t.auto_type = t.selected_type);
        renderTransactionRows();
        showStep(2);
    });

    startOverButton.addEventListener('click', () => {
        document.getElementById('message').value = '';
        showStep(1);
    });

    // Initial setup
    showStep(1);
});
