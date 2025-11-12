jQuery(document).ready(function($) {
  const payButton = document.getElementById('evm-payment-button');
  if (payButton) {
    payButton.addEventListener('click', async function() {
      try {
        // Check if MetaMask is installed
        if (typeof window.ethereum === 'undefined') {
          alert('MetaMask is not installed! Please install MetaMask to make payments.');
          return;
        }

        // Get token information
        const tokenName = evmPaymentData.tokenName || 'Token';
        const tokenSymbol = evmPaymentData.tokenSymbol || 'TKN';

        // Request account access
        const accounts = await ethereum.request({ method: 'eth_requestAccounts' });
        const account = accounts[0];

        // Show processing state
        payButton.disabled = true;
        payButton.innerHTML = 'Processing ' + tokenSymbol + ' payment...';
        
        // Get payment amount
        const amount = evmPaymentConfig.amount;
        
        // Load Web3
        const web3 = new Web3(window.ethereum);
        
        // Get contract details
        const contractAddress = evmPaymentData.contractAddress;
        const targetAddress = evmPaymentData.targetAddress;
        const decimals = parseInt(evmPaymentData.tokenDecimals);
        
        // Calculate token amount (with proper decimals)
        const factor = new web3.utils.BN(10).pow(new web3.utils.BN(decimals));
        const value = new web3.utils.BN(Math.round(amount * 100)).mul(factor).div(new web3.utils.BN(100));
        
        // Create contract instance
        const contract = new web3.eth.Contract(evmPaymentData.abiArray, contractAddress);
        
        // Execute the transfer
        const result = await contract.methods.transfer(targetAddress, value.toString()).send({ from: account });
        
        // Notify server of payment (continue even if this fails)
        try {
          $.post(
            evmPaymentData.ajaxUrl,
            {
              action: 'verify_payment',
              nonce: evmPaymentData.nonce,
              order_id: evmPaymentConfig.orderId,
              tx: result.transactionHash
            }
          );
        } catch (ajaxError) {
          console.error('Server notification error:', ajaxError);
        }
        
        // Show success popup instead of redirecting
        showSuccessPopup(result.transactionHash, tokenSymbol);
        
      } catch (error) {
        // Reset button state
        payButton.disabled = false;
        payButton.innerHTML = 'Pay with MetaMask';
        
        // Show error
        const errorMessage = error.code === 4001 ? 'Transaction was rejected by user' : error.message;
        const errorDiv = document.getElementById('evm-payment-error');
        errorDiv.textContent = errorMessage;
        errorDiv.style.display = 'block';
        errorDiv.className = 'woocommerce-error';
        
        console.error('Payment error:', error);
      }
    });
  }
  
  // Create and show a centered popup with payment success details
  function showSuccessPopup(txHash, tokenSymbol) {
    tokenSymbol = tokenSymbol || 'TKN';
    // Create modal container
    const modal = document.createElement('div');
    modal.style.position = 'fixed';
    modal.style.left = '0';
    modal.style.top = '0';
    modal.style.width = '100%';
    modal.style.height = '100%';
    modal.style.backgroundColor = 'rgba(0,0,0,0.7)';
    modal.style.zIndex = '9999';
    modal.style.display = 'flex';
    modal.style.alignItems = 'center';
    modal.style.justifyContent = 'center';
    
    // Create modal content
    const modalContent = document.createElement('div');
    modalContent.style.backgroundColor = '#ffffff';
    modalContent.style.borderRadius = '8px';
    modalContent.style.padding = '30px';
    modalContent.style.width = '80%';
    modalContent.style.maxWidth = '500px';
    modalContent.style.maxHeight = '80%';
    modalContent.style.overflowY = 'auto';
    modalContent.style.boxShadow = '0 4px 8px rgba(0,0,0,0.2)';
    modalContent.style.textAlign = 'center';
    
    // Add success icon
    const icon = document.createElement('div');
    icon.innerHTML = '✅';
    icon.style.fontSize = '48px';
    icon.style.marginBottom = '20px';
    modalContent.appendChild(icon);
    
    // Add title
    const title = document.createElement('h2');
    title.innerHTML = 'Payment Successful!';
    title.style.fontSize = '24px';
    title.style.marginBottom = '20px';
    title.style.color = '#4CAF50';
    modalContent.appendChild(title);

    // Add message
    const message = document.createElement('p');
    message.innerHTML = 'Your ' + tokenSymbol + ' payment has been confirmed on the blockchain.';
    message.style.marginBottom = '15px';
    modalContent.appendChild(message);
    
    // Add transaction ID
    const txIdContainer = document.createElement('div');
    txIdContainer.style.padding = '10px';
    txIdContainer.style.backgroundColor = '#f5f5f5';
    txIdContainer.style.borderRadius = '4px';
    txIdContainer.style.marginBottom = '25px';
    txIdContainer.style.wordBreak = 'break-all';
    txIdContainer.style.fontSize = '14px';
    
    const txIdLabel = document.createElement('div');
    txIdLabel.innerHTML = 'Transaction ID:';
    txIdLabel.style.fontWeight = 'bold';
    txIdLabel.style.marginBottom = '5px';
    txIdContainer.appendChild(txIdLabel);
    
    const txIdValue = document.createElement('div');
    txIdValue.innerHTML = txHash;
    txIdContainer.appendChild(txIdValue);
    modalContent.appendChild(txIdContainer);
    
    // Add close button
    const closeButton = document.createElement('button');
    closeButton.innerHTML = 'Close';
    closeButton.style.padding = '10px 25px';
    closeButton.style.backgroundColor = '#4CAF50';
    closeButton.style.color = 'white';
    closeButton.style.border = 'none';
    closeButton.style.borderRadius = '4px';
    closeButton.style.cursor = 'pointer';
    closeButton.style.fontSize = '16px';
    closeButton.style.fontWeight = 'bold';
    closeButton.style.marginRight = '10px';
    closeButton.addEventListener('click', function() {
      document.body.removeChild(modal);
    });
    modalContent.appendChild(closeButton);
    
    // Add "View Order Details" button
    const viewOrderButton = document.createElement('button');
    viewOrderButton.innerHTML = 'View Order Details';
    viewOrderButton.style.padding = '10px 25px';
    viewOrderButton.style.backgroundColor = '#2196F3';
    viewOrderButton.style.color = 'white';
    viewOrderButton.style.border = 'none';
    viewOrderButton.style.borderRadius = '4px';
    viewOrderButton.style.cursor = 'pointer';
    viewOrderButton.style.fontSize = '16px';
    viewOrderButton.style.fontWeight = 'bold';
    viewOrderButton.addEventListener('click', function() {
      window.location.href = '/index.php/checkout/order-received/' + evmPaymentConfig.orderId + '/';
    });
    modalContent.appendChild(viewOrderButton);
    
    // Add modal to page
    modal.appendChild(modalContent);
    document.body.appendChild(modal);
    
    // Reset button state
    const payButton = document.getElementById('evm-payment-button');
    if (payButton) {
      payButton.disabled = false;
      payButton.innerHTML = 'Payment Complete';
    }
    
    // Also update the message area
    const errorDiv = document.getElementById('evm-payment-error');
    if (errorDiv) {
      errorDiv.textContent = tokenSymbol + ' payment confirmed! Transaction ID: ' + txHash;
      errorDiv.style.display = 'block';
      errorDiv.className = 'woocommerce-message';
    }
  }
});
