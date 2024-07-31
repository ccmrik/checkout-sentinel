const { registerCheckoutFilters } = wc.blocksCheckout;

registerCheckoutFilters('checkout-sentinel', {
    submitButtonValidationProps: (defaultProps) => {
        return {
            ...defaultProps,
            onClick: async (e) => {
                e.preventDefault();
                const response = await fetch('/wp-admin/admin-ajax.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=checkout_sentinel_check',
                });
                const result = await response.json();
                if (result.error) {
                    wc.blocksCheckout.setValidationErrors({
                        'checkout-sentinel': result.message,
                    });
                } else {
                    defaultProps.onClick(e);
                }
            },
        };
    },
});