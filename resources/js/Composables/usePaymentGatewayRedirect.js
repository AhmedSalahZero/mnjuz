import axios from 'axios'

/**
 * Start a hosted payment and redirect the browser to the gateway checkout page.
 */
export async function redirectToPaymentGateway(url, payload) {
    try {
        const response = await axios.post(url, {
            ...payload,
            redirect_json: true,
        })

        const data = response.data

        if (data?.success && data?.redirect_url) {
            window.location.href = data.redirect_url
            return { redirected: true }
        }

        throw new Error(data?.message || 'Could not initialize payment.')
    } catch (error) {
        const message = error.response?.data?.message
            || error.response?.data?.errors?.amount?.[0]
            || error.response?.data?.errors?.method?.[0]
            || error.message

        throw new Error(message)
    }
}
