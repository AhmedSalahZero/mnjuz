export function useRecaptcha(siteKey) {
    const script = document.createElement('script');
    script.src = `https://www.google.com/recaptcha/api.js?render=${siteKey}`;
    script.async = true;
    script.defer = true;

    // Check if the script is already loaded to avoid duplicates
    if (!document.querySelector(`script[src="https://www.google.com/recaptcha/api.js?render=${siteKey}"]`)) {
        document.head.appendChild(script);
    }
}

export function unMountRecaptcha(siteKey) {
    const scriptSrc = `https://www.google.com/recaptcha/api.js?render=${siteKey}`;
    const scriptElement = document.querySelector(`script[src="${scriptSrc}"]`);

    if (scriptElement) {
        document.head.removeChild(scriptElement);
    }
}