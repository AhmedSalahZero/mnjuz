import { ref } from 'vue';
import axios from 'axios';

export function useTableFormData(props, initialForm, initialFormInputs) {
    const currentUrl = window.location.href;
    const formUrl = ref(currentUrl);
    const isOpenFormModal = ref(false);

    const form = ref(initialForm);
    const formInputs = initialFormInputs;

    const deleteAction = async (key) => {
        try {
            await axios.delete(`${currentUrl}/${key}`);
            const idx = props.rows.data.findIndex((i) => i.id === key);
            props.rows.data.splice(idx, 1);
        } catch (error) {
            // Handle error
        }
    };

    function openModal(key, formData = {}) {
        formUrl.value = key ? `${currentUrl}/${key}` : currentUrl;

        if (key) {
            getRow();
        }

        for (const key in formData) {
            if (form.hasOwnProperty(key)) {
                form[key] = formData[key];
            }
        }

        isOpenFormModal.value = true;
    }

    function getRow() {
        axios
        .get(formUrl.value)
        .then((response) => {
            const { data } = response;
            for (const key in data.item) {
                if (form.value.hasOwnProperty(key)) {
                    form.value[key] = data.item[key];
                }
            }
        })
        .catch((error) => {
            // console.error('Error:', error);
        });
    }

    return { currentUrl, props, formUrl, isOpenFormModal, form, formInputs, deleteAction, openModal, getRow };
}
