window.addEventListener('load', function () {
    const form = document.querySelector('.wc-block-checkout__form');
    const placeOrderButton = document.querySelector('.wc-block-components-checkout-place-order-button');

    if (!form) {
        console.error('Checkout form not found');
        return;
    }
    console.log('Checkout form found');

    if (!placeOrderButton) {
        console.error('Place Order button not found');
        return;
    }
    console.log('Place Order button found');

    const emailField = form.querySelector('input[id="email"]');
    const firstNameField = form.querySelector('input[id="shipping-first_name"]');
    const lastNameField = form.querySelector('input[id="shipping-last_name"]');
    const addressField = form.querySelector('input[id="shipping-address_1"]');
    const cityField = form.querySelector('input[id="shipping-city"]');
    const stateField = form.querySelector('select[id="shipping-state"]');
    const zipCodeField = form.querySelector('input[id="shipping-postcode"]');
    const phoneField = form.querySelector('input[id="shipping-phone"]');

    if (!emailField || !firstNameField || !lastNameField || !zipCodeField || !phoneField) {
        console.error('One or more form fields not found');
        return;
    }

    function showError(field, message) {
        let errorElement = field.parentElement.querySelector('.wc-block-components-validation-error');
        if (!errorElement) {
            errorElement = document.createElement('div');
            errorElement.className = 'wc-block-components-validation-error';
            errorElement.setAttribute('role', 'alert');
            errorElement.style.color = 'red';
            field.parentElement.appendChild(errorElement);
        }
        errorElement.innerHTML = `<p>${message}</p>`;
        field.classList.add('input-error');
    }

    function clearError(field) {
        const errorElement = field.parentElement.querySelector('.wc-block-components-validation-error');
        if (errorElement) {
            errorElement.remove();
        }
        field.classList.remove('input-error');
    }

    function validateEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(String(email).toLowerCase());
    }

    function validateZipCode(zipCode) {
        const re = /^\d{5}(-\d{4})?$/;
        return re.test(String(zipCode));
    }

    function validatePhone(phone) {
        if (!phone) return true; // Phone is optional
        const re = /^\d{10}$/;  // Assuming a 10-digit format for US phone numbers
        return re.test(String(phone));
    }

    function validateNotEmpty(field, message) {
        if (field.value.trim() === '') {
            showError(field, message);
            return false;
        } else {
            clearError(field);
            return true;
        }
    }

    // Real-time validation as user types in fields
    emailField.addEventListener('input', function () {
        if (!validateEmail(emailField.value)) {
            showError(emailField, 'Please enter a valid email address.');
        } else {
            clearError(emailField);
        }
    });

    firstNameField.addEventListener('input', function () {
        if (firstNameField.value.trim() === '') {
            showError(firstNameField, 'First name is required.');
        } else {
            clearError(firstNameField);
        }
    });

    lastNameField.addEventListener('input', function () {
        if (lastNameField.value.trim() === '') {
            showError(lastNameField, 'Last name is required.');
        } else {
            clearError(lastNameField);
        }
    });

    zipCodeField.addEventListener('input', function () {
        if (!validateZipCode(zipCodeField.value)) {
            showError(zipCodeField, 'Please enter a valid ZIP code.');
        } else {
            clearError(zipCodeField);
        }
    });

    phoneField.addEventListener('input', function () {
        if (!validatePhone(phoneField.value)) {
            showError(phoneField, 'Please enter a valid phone number.');
        } else {
            clearError(phoneField);
        }
    });

    addressField.addEventListener('input', function () {
        validateNotEmpty(addressField, 'Address is required.');
    });

    cityField.addEventListener('input', function () {
        validateNotEmpty(cityField, 'City is required.');
    });

    // Validate when the Place Order button is clicked
    placeOrderButton.addEventListener('click', function (event) {
        let isValid = true;
        console.log('Place Order button clicked');

        if (!validateEmail(emailField.value)) {
            showError(emailField, 'Please enter a valid email address.');
            isValid = false;
        } else {
            clearError(emailField);
        }

        if (firstNameField.value.trim() === '') {
            showError(firstNameField, 'First name is required.');
            isValid = false;
        } else {
            clearError(firstNameField);
        }

        if (lastNameField.value.trim() === '') {
            showError(lastNameField, 'Last name is required.');
            isValid = false;
        } else {
            clearError(lastNameField);
        }

        if (!validateZipCode(zipCodeField.value)) {
            showError(zipCodeField, 'Please enter a valid ZIP code.');
            isValid = false;
        } else {
            clearError(zipCodeField);
        }

        if (!validatePhone(phoneField.value)) {
            showError(phoneField, 'Please enter a valid phone number.');
            isValid = false;
        } else {
            clearError(phoneField);
        }

        if (!isValid) {
            event.preventDefault();
        } else {
            form.submit();  // Manually submit the form if validation passes
        }
    });
});
