<template>
  <SettingLayout :modules="props.modules">
    <div class="md:h-[90vh]">
      <div class="flex justify-center items-center">
        <div class="md:w-[60em]">
          <div class="bg-white border border-slate-200 rounded-lg pt-2 text-sm mb-4 px-4 mb-20">
            <div class="w-full py-2 mb-4 mt-2">
              <div class="flex w-full">
                <div class="text-md">
                  <h4 class="text-[16px]">{{ $t('Enable ticketing') }}</h4>
                  <div class="mb-1 text-slate-500">
                    {{ $t('Activate ticketing workflow in your conversations') }}
                  </div>
                </div>
                <div class="ml-auto">
                  <div
                    class="w-12 h-6 flex items-center bg-gray-300 rounded-full p-1"
                    :class="{ 'bg-primary': form.active }"
                    @click="toggleState1(active)">
                    <div
                      class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out"
                      :class="{ 'translate-x-6': form.active }"></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div
            v-if="form.active"
            class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4 pb-4 px-4 mb-20">
            <div class="w-full py-2 mb-2 mt-2">
              <div class="flex w-full mb-4">
                <div class="text-md">
                  <h4 class="text-[16px]">{{ $t('Auto assignment') }}</h4>
                  <span class="flex items-center mt-1 text-slate-500">
                    {{
                      $t(
                        'Use auto-assignment rules to evenly distribute chats among agents automatically.',
                      )
                    }}
                  </span>
                </div>
              </div>
              <div class="w-5/5">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div
                    v-for="mode in assignmentModes"
                    :key="mode.value"
                    class="border rounded-xl p-4 cursor-pointer"
                    :class="form.assignment_mode === mode.value ? 'border-black' : ''"
                    @click="selectAssignmentMode(mode.value)">
                    <div class="flex space-x-2">
                      <div>
                        <div class="flex mt-[1px]">
                          <div
                            class="w-4 h-4 border border-gray-400 rounded-md flex items-center justify-center"
                            :class="form.assignment_mode === mode.value ? 'bg-[#000]' : ''">
                            <svg
                              v-if="form.assignment_mode === mode.value"
                              class="w-4 h-4 text-white"
                              fill="none"
                              stroke="currentColor"
                              viewBox="0 0 24 24"
                              xmlns="http://www.w3.org/2000/svg">
                              <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M5 13l4 4L19 7"></path>
                            </svg>
                          </div>
                        </div>
                      </div>
                      <div>
                        <div>{{ $t(mode.label) }}</div>
                        <div class="text-slate-500">{{ $t(mode.description) }}</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div
            v-if="form.active"
            class="bg-white border border-slate-200 rounded-lg pt-2 text-sm mb-4 px-4 mb-20">
            <div class="w-full py-2 mb-4 mt-2">
              <div class="flex w-full">
                <div class="w-3/4 text-md">
                  <h4 class="text-[16px]">{{ $t('Reassign chats that have been reopened') }}</h4>
                  <div class="mb-1 text-slate-500">
                    {{
                      $t(
                        'Enable this option to reassign chats when a contact re-opens a closed conversation. If disabled, reopened chats will either return to the previous agent or remain unassigned, based on auto-assignment settings.',
                      )
                    }}
                  </div>
                </div>
                <div class="w-1/4">
                  <div
                    class="ml-auto w-12 h-6 flex items-center bg-gray-300 rounded-full p-1"
                    :class="{ 'bg-primary': form.reassign_reopened_chats }"
                    @click="toggleState2()">
                    <div
                      class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out"
                      :class="{ 'translate-x-6': form.reassign_reopened_chats }"></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div
            v-if="form.active"
            class="bg-white border border-slate-200 rounded-lg pt-2 text-sm mb-4 px-4 mb-20">
            <div class="w-full py-2 mb-4 mt-2">
              <div class="flex w-full">
                <div class="w-3/4 text-md">
                  <h4 class="text-[16px]">
                    {{ $t('Grant agents access to view all chats not assigned to them') }}
                  </h4>
                  <div class="mb-1 text-slate-500">
                    {{
                      $t(
                        'Disable this option, if you want live chat agents to have access only to new conversations and conversations that are assigned to them.',
                      )
                    }}
                  </div>
                </div>
                <div class="w-1/4">
                  <div
                    class="ml-auto w-12 h-6 flex items-center bg-gray-300 rounded-full p-1"
                    :class="{ 'bg-primary': form.allow_agents_to_view_all_chats }"
                    @click="toggleState3()">
                    <div
                      class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out"
                      :class="{ 'translate-x-6': form.allow_agents_to_view_all_chats }"></div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div
            v-if="form.active"
            class="bg-white border border-slate-200 rounded-lg pt-2 text-sm px-4 mb-20">
            <div class="w-full py-2 mb-4 mt-2">
              <div class="flex w-full">
                <div class="w-3/4 text-md">
                  <h4 class="text-[16px]">{{ $t('Encrypt Contact Numbers For Agents ?') }}</h4>
                  <div class="mb-1 text-slate-500">
                    {{
                      $t(
                        'Enable this option, if you want to encrypt Contact Numbers For Agents For Example The Contact Number Will Be Displayed As 123******.',
                      )
                    }}
                  </div>
                </div>
                <div class="w-1/4">
                  <div
                    class="ml-auto w-12 h-6 flex items-center bg-gray-300 rounded-full p-1"
                    :class="{ 'bg-primary': form.encrypt_contacts_for_agents }"
                    @click="toggleState4()">
                    <div
                      class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out"
                      :class="{ 'translate-x-6': form.encrypt_contacts_for_agents }"></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </SettingLayout>
</template>
<script setup>
import SettingLayout from './Layout.vue'
import { ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useTrans } from '@/Composables/useTrans'

const trans = useTrans()

const props = defineProps(['rows', 'filters', 'settings', 'modules'])
const config = ref(props.settings.metadata)
const settings = ref(config.value ? JSON.parse(config.value) : null)

// وضع الإسناد: يدوي | تلقائي (توزيع على موظف واحد) | مشترك (يظهر للجميع)
// نستنتج القيمة الافتراضية من الإعداد القديم auto_assignment لضمان التوافق.
const initialMode =
  settings.value?.tickets?.assignment_mode ??
  (settings.value?.tickets?.auto_assignment ? 'auto' : 'manual')

const assignmentModes = [
  {
    value: 'manual',
    label: 'Off',
    description: 'Team members pick conversations manually from Unassigned folder.',
  },
  {
    value: 'auto',
    label: 'Auto',
    description: 'Distribute conversations among all your available team members.',
  },
  {
    value: 'round_robin',
    label: 'Round robin',
    description: 'Assign conversations in order: agent 1, then 2, then 3, then back to 1.',
  },
  {
    value: 'shared',
    label: 'All employees',
    description: 'Show every conversation to all employees so anyone can reply.',
  },
]

const form = useForm({
  active: settings.value?.tickets?.active ?? false,
  assignment_mode: initialMode,
  auto_assignment: ['auto', 'round_robin'].includes(initialMode),
  reassign_reopened_chats: settings.value?.tickets?.reassign_reopened_chats ?? false,
  allow_agents_to_view_all_chats: settings.value?.tickets?.allow_agents_to_view_all_chats ?? false,
  encrypt_contacts_for_agents: settings.value?.tickets?.encrypt_contacts_for_agents ?? false,
})

const toggleState1 = () => {
  form.active = !form.active
  submitForm()
}

const toggleState2 = () => {
  form.reassign_reopened_chats = !form.reassign_reopened_chats
  submitForm()
}

const toggleState3 = () => {
  form.allow_agents_to_view_all_chats = !form.allow_agents_to_view_all_chats
  submitForm()
}
const toggleState4 = () => {
  form.encrypt_contacts_for_agents = !form.encrypt_contacts_for_agents
  submitForm()
}

const selectAssignmentMode = (mode) => {
  form.assignment_mode = mode
  // نبقي auto_assignment متزامناً مع أوضاع الإسناد التلقائي (auto + round_robin).
  form.auto_assignment = ['auto', 'round_robin'].includes(mode)
  submitForm()
}

const submitForm = async () => {
  form.post('/settings/tickets', {
    preserveScroll: true,
  })
}
</script>
