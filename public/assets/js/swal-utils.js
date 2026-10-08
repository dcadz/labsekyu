// assets/js/swal-utils.js
// SweetAlert2 wrapper functions to replace native alert(), confirm(), and prompt()

async function swalAlert(message, title = 'Notification', icon = 'info') {
    return Swal.fire({
        title: title,
        text: message,
        icon: icon,
        confirmButtonColor: '#3085d6'
    });
}

async function swalConfirm(message, title = 'Are you sure?', confirmText = 'Yes', cancelText = 'Cancel') {
    const result = await Swal.fire({
        title: title,
        text: message,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: confirmText,
        cancelButtonText: cancelText
    });
    return result.isConfirmed;
}

async function swalPrompt(message, title = 'Input required', inputType = 'text', placeholder = '') {
    const result = await Swal.fire({
        title: title,
        text: message,
        input: inputType,
        inputPlaceholder: placeholder,
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        inputValidator: (value) => {
            if (!value) {
                return 'You need to write something!';
            }
        }
    });
    return result.isConfirmed ? result.value : null;
}
