import { ref } from 'vue';

export function useAlertModal() {
  const isOpenAlert = ref(false);
  const selectedItem = ref(null);

  function openAlert(key) {
    isOpenAlert.value = true;
    selectedItem.value = key;
  }

  async function confirmAlert(action) {
    try {
      isOpenAlert.value = false;
      await action(selectedItem.value);
    } catch (error) {
      // Handle error
      console.error(error);
    }
    selectedItem.value = null;
  }

  function closeAlert() {
    isOpenAlert.value = false;
    selectedItem.value = null;
  }

  return {
    isOpenAlert,
    selectedItem,
    openAlert,
    confirmAlert,
    closeAlert,
  };
}