<script setup>
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    event: Object,     // ✅ Event details (includes table_name)
    attendees: Array   // ✅ List of attendees from the dynamic table
});

const searchQuery = ref("");

// ✅ Extract column names from the first attendee, excluding "created_at" and "updated_at"
const columnHeaders = computed(() => {
    if (props.attendees.length > 0) {
        return Object.keys(props.attendees[0]).filter(col => !["created_at", "updated_at", "id", "event_id"].includes(col));
    }
    return [];
});

// ✅ Format column headers (replace underscores with spaces and capitalize)
const formatHeader = (header) => {
    return header.replace(/_/g, " ").replace(/\b\w/g, (char) => char.toUpperCase());
};

// ✅ Computed property to filter attendees
const filteredAttendees = computed(() => {
    if (!searchQuery.value) {
        return props.attendees;
    }
    return props.attendees.filter(attendee =>
        Object.entries(attendee)
            .filter(([key]) => !["created_at", "updated_at", "id", "event_id"].includes(key)) // ✅ Exclude from filtering as well
            .some(([_, value]) =>
                value && value.toString().toLowerCase().includes(searchQuery.value.toLowerCase())
            )
    );
});
</script>

<template>
    <Head title="Attendees" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Attendees for {{ event.event_name }}
            </h2>
        </template>

        <div class="p-2 pb-5 pt-5">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="flex justify-end mb-3">
                            <!-- ✅ Search Bar -->
                            <input
                                v-model="searchQuery"
                                type="text"
                                placeholder="Search attendees..."
                                class="w-full md:w-1/3 p-2 border rounded"
                            />
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse border border-gray-300">
                                <!-- ✅ Sticky Header -->
                                <thead class="bg-gray-200 sticky top-0">
                                    <tr>
                                        <th v-for="(col, index) in columnHeaders" :key="index" class="border border-gray-300 p-2 whitespace-nowrap">
                                            {{ formatHeader(col) }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(attendee, index) in filteredAttendees" :key="index" class="text-left even:bg-gray-100">
                                        <td v-for="(col, index) in columnHeaders" :key="index" class="border border-gray-300 p-2 break-words">
                                            {{ attendee[col] }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="attendees.length === 0" class="text-gray-600 text-center mt-4">
                            No attendees have registered for this event.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
