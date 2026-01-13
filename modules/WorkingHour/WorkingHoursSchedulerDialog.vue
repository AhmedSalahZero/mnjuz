<template>
  <Dialog v-model:open="isOpen">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Working Schedule</DialogTitle>
      </DialogHeader>
      <form
        id="work-schedule-form"
        v-if="selectedTeamMember"
        @submit.prevent="onSubmit"
        class="max-h-[75dvh] flex-1 overflow-auto"
      >
        <div class="mb-5 flex items-center gap-x-3">
          <FormToggleSwitch v-model="hasWorkingHours" />
          <span class="text-base font-medium">Enable working hours</span>
        </div>
        <Accordion
          type="single"
          collapsible
          :class="
            cn('flex flex-col gap-y-3', {
              'pointer-events-none opacity-40': !hasWorkingHours,
            })
          "
        >
          <AccordionItem
            v-for="(daySchedule, dayIndex) in schedule"
            :key="daySchedule.day"
            :value="daySchedule.day"
            class="rounded-xs border-2 data-[state=closed]:border-muted-foreground/30 data-[state=open]:border-primary"
          >
            <div class="flex items-stretch justify-between">
              <div class="flex items-center gap-x-3 py-4 pl-4">
                <FormToggleSwitch v-model="schedule[dayIndex].active" />
                <span class="text-lg font-medium">
                  {{
                    daySchedule.day.charAt(0).toUpperCase() +
                    daySchedule.day.slice(1)
                  }}
                </span>
                <span
                  v-if="getFirstValidShift(daySchedule) && daySchedule.active"
                  class="text-xs text-muted-foreground"
                >
                  {{ formatTime(getFirstValidShift(daySchedule)?.start_time) }}
                  -
                  {{ formatTime(getFirstValidShift(daySchedule)?.end_time) }}
                  <span v-if="daySchedule.shifts.length > 1">
                    (+{{ daySchedule.shifts.length - 1 }})
                  </span>
                </span>
              </div>
              <AccordionTrigger
                class="grow py-0 pr-4 pl-6 [&>svg]:size-5 [&[data-state=open]>svg]:text-primary"
              />
            </div>
            <AccordionContent
              class="pb-0"
              wrapper-class="overflow-visible data-[state=open]:overflow-visible data-[state=closed]:overflow-hidden"
            >
              <div class="flex flex-col gap-y-3 px-4 py-4">
                <div
                  v-for="(shift, shiftIndex) in daySchedule.shifts"
                  :key="shiftIndex"
                  class="flex items-center gap-x-3"
                >
                  <MultiSelect
                    class="flex-1"
                    :name="`${daySchedule.day}-${shiftIndex}-start`"
                    :options="getStartTimeOptions(dayIndex, shiftIndex)"
                    v-model="schedule[dayIndex].shifts[shiftIndex].start_time"
                    mode="single"
                    :searchable="true"
                    placeholder="Start time"
                  />
                  <span class="text-base font-medium">To</span>
                  <MultiSelect
                    class="flex-1"
                    :name="`${daySchedule.day}-${shiftIndex}-end`"
                    :options="getEndTimeOptions(dayIndex, shiftIndex)"
                    v-model="schedule[dayIndex].shifts[shiftIndex].end_time"
                    mode="single"
                    :searchable="true"
                    placeholder="End time"
                  />
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    :disabled="daySchedule.shifts.length <= 1"
                    @click="removeShift(dayIndex, shiftIndex)"
                  >
                    <Trash2 class="size-4 text-destructive" />
                  </Button>
                </div>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  class="w-fit"
                  :disabled="!canAddShift(daySchedule)"
                  @click="addShift(dayIndex)"
                >
                  <Plus class="size-4" />
                  Add shift
                </Button>
              </div>
            </AccordionContent>
          </AccordionItem>
        </Accordion>
      </form>

      <DialogFooter class="justify-end gap-2">
        <DialogClose as-child>
          <Button variant="secondary">Cancel</Button>
        </DialogClose>
        <Button
          type="submit"
          form="work-schedule-form"
          :disabled="form.processing"
        >
          <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
          <span v-else>Save</span>
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
<script lang="ts" setup>
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/Components/ui/dialog";
import { useForm } from "vee-validate";
import { useForm as useInertiaForm } from "@inertiajs/vue3";
import { toTypedSchema } from "@vee-validate/zod";
import { TeamMember } from "@/Pages/User/Team/Index.vue";
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/Components/ui/accordion";
import FormToggleSwitch from "@/Components/FormToggleSwitch.vue";
import MultiSelect from "@/Components/MultiSelect.vue";
import { Button } from "@/Components/ui/button";
import { ref, watch } from "vue";
import {
  workingHoursSchema,
  getDefaultSchedule,
  flattenSchedule,
  groupScheduleByDay,
  type DaySchedule,
  type Shift,
} from "@/Composables/useWorkingHoursSchedule";
import { toast } from "vue-sonner";
import { LoaderCircle, Plus, Trash2 } from "lucide-vue-next";
import { cn } from "@/lib/utils";

const props = defineProps<{ selectedTeamMember: TeamMember | null }>();

const isOpen = defineModel<boolean>({ default: false });

// Generate start time options in 30-minute intervals (00:00 - 23:30)
// Values: 00:00:00, 00:30:00, 01:00:00, ... 23:30:00
const startTimeOptions = Array.from({ length: 48 }, (_, i) => {
  const hours = Math.floor(i / 2)
    .toString()
    .padStart(2, "0");
  const minutes = i % 2 === 0 ? "00" : "30";
  return { label: `${hours}:${minutes}`, value: `${hours}:${minutes}:00` };
});

// Generate end time options in 30-minute intervals (00:30 - 24:00)
// Values: 00:29:59, 00:59:59, 01:29:59, ... 23:59:59
// Labels: 00:30, 01:00, 01:30, ... 24:00
const endTimeOptions = Array.from({ length: 48 }, (_, i) => {
  const displayHours = Math.floor((i + 1) / 2);
  const displayMinutes = (i + 1) % 2 === 0 ? "00" : "30";
  const displayLabel =
    displayHours === 24
      ? "24:00"
      : `${displayHours.toString().padStart(2, "0")}:${displayMinutes}`;

  const valueHours = Math.floor(i / 2)
    .toString()
    .padStart(2, "0");
  const valueMinutes = i % 2 === 0 ? "29" : "59";
  return { label: displayLabel, value: `${valueHours}:${valueMinutes}:59` };
});

// Format time string for display
// Start times: HH:MM:00 -> HH:MM
// End times: HH:29:59 -> HH:30, HH:59:59 -> (HH+1):00
const formatTime = (time: string | null | undefined): string => {
  if (!time) return "";

  // Handle end times (values ending in :59)
  if (time.endsWith(":59")) {
    const [hours, minutes] = time.split(":");
    const h = parseInt(hours, 10);
    const m = parseInt(minutes, 10);

    if (m === 29) {
      // HH:29:59 -> HH:30
      return `${hours}:30`;
    } else if (m === 59) {
      // HH:59:59 -> (HH+1):00
      const nextHour = h + 1;
      if (nextHour === 24) return "24:00";
      return `${nextHour.toString().padStart(2, "0")}:00`;
    }
  }

  // Start times: just remove seconds
  return time.slice(0, 5);
};

// Get the first valid shift (with both start and end times)
const getFirstValidShift = (daySchedule: DaySchedule): Shift | undefined => {
  return daySchedule.shifts.find((s) => s.start_time && s.end_time);
};

// Get available start time options for a shift
const getStartTimeOptions = (dayIndex: number, shiftIndex: number) => {
  const shifts = schedule.value[dayIndex].shifts;

  // For first shift, show all start times
  if (shiftIndex === 0) {
    return startTimeOptions;
  }

  // For other shifts, start time must be > previous shift's end time
  const prevShift = shifts[shiftIndex - 1];
  if (!prevShift.end_time) return startTimeOptions;

  return startTimeOptions.filter((opt) => opt.value > prevShift.end_time);
};

// Get available end time options for a shift
const getEndTimeOptions = (dayIndex: number, shiftIndex: number) => {
  const shifts = schedule.value[dayIndex].shifts;
  const currentShift = shifts[shiftIndex];

  // Must be > current start time
  const minTime = currentShift.start_time || "00:00:00";

  // If there's a next shift, must be < next shift's start time
  const nextShift = shifts[shiftIndex + 1];
  const maxTime = nextShift?.start_time;

  return endTimeOptions.filter((opt) => {
    if (opt.value <= minTime) return false;
    if (maxTime && opt.value >= maxTime) return false;
    return true;
  });
};

const { handleSubmit, resetForm, setFieldValue } = useForm({
  validationSchema: toTypedSchema(workingHoursSchema),
  initialValues: { schedule: getDefaultSchedule() },
});

// Reactive schedule for v-model bindings
const schedule = ref<DaySchedule[]>(getDefaultSchedule());
const hasWorkingHours = ref(false);

// Load existing working hours when dialog opens
watch(
  () => props.selectedTeamMember,
  (member) => {
    hasWorkingHours.value = member?.has_working_hours ?? false;
    if (member?.working_hours?.length) {
      schedule.value = groupScheduleByDay(member.working_hours);
    } else {
      schedule.value = getDefaultSchedule();
    }
    setFieldValue("schedule", schedule.value);
  },
);

// Sync schedule changes to VeeValidate
watch(
  schedule,
  (newVal) => {
    setFieldValue("schedule", newVal);
  },
  { deep: true },
);

const form = useInertiaForm({ schedule: getDefaultSchedule() });

// Check if we can add a new shift (last shift must not end at 23:59:59)
const canAddShift = (daySchedule: DaySchedule): boolean => {
  const lastShift = daySchedule.shifts[daySchedule.shifts.length - 1];
  return lastShift.end_time !== "23:59:59";
};

// Convert end time value to corresponding start time value
// e.g., 08:29:59 -> 08:30:00, 08:59:59 -> 09:00:00
const endTimeToStartTime = (endTime: string): string => {
  const [hours, minutes] = endTime.split(":");
  const h = parseInt(hours, 10);
  const m = parseInt(minutes, 10);

  if (m === 29) {
    return `${hours}:30:00`;
  } else if (m === 59) {
    const nextHour = (h + 1).toString().padStart(2, "0");
    return `${nextHour}:00:00`;
  }
  return endTime;
};

// Add a new shift to a day
const addShift = (dayIndex: number) => {
  const lastShift =
    schedule.value[dayIndex].shifts[schedule.value[dayIndex].shifts.length - 1];
  const newStartTime = lastShift.end_time
    ? endTimeToStartTime(lastShift.end_time)
    : "";
  schedule.value[dayIndex].shifts.push({
    start_time: newStartTime,
    end_time: "23:59:59",
  });
};

// Remove a shift from a day (minimum 1 shift required)
const removeShift = (dayIndex: number, shiftIndex: number) => {
  if (schedule.value[dayIndex].shifts.length > 1) {
    schedule.value[dayIndex].shifts.splice(shiftIndex, 1);
  }
};

const onSubmit = handleSubmit(
  (validatedValues) => {
    console.log("Validation passed, schedule:", validatedValues.schedule);
    const flattenedData = flattenSchedule(validatedValues.schedule);
    console.log("Flattened data:", flattenedData);
    form
      .transform(() => ({
        has_working_hours: hasWorkingHours.value,
        working_hours: flattenedData,
      }))
      .put(`/team/${props.selectedTeamMember?.uuid}`, {
        onSuccess: () => {
          isOpen.value = false;
          toast.success("Working hours updated successfully");
        },
        onError: (errors) => {
          const firstError = Object.values(errors)[0];
          toast.error(firstError as string);
        },
      });
  },
  (errors) => {
    console.error("Validation failed, errors:", errors);

    const firstError = Object.values(errors.errors)[0];
    toast.error(firstError as string);
  },
);
</script>
