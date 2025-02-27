<script setup>
import { ref, computed } from 'vue';
import Swal from 'sweetalert2';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';

const props = defineProps({
    event: Object,
    attendees: Array
});

const searchQuery = ref("");
const currentPage = ref(1);
const itemsPerPage = 20;

// ✅ Extract column names (exclude unwanted columns)
const columnHeaders = computed(() => {
    if (props.attendees.length > 0) {
        return Object.keys(props.attendees[0]).filter(col => !["created_at", "updated_at", "id", "event_id", "location_id", "events_location", "mobile_number_format"].includes(col));
    }
    return [];
});

// ✅ Format column headers
const formatHeader = (header) => {
    return header.replace(/_/g, " ").replace(/\b\w/g, (char) => char.toUpperCase());
};

// ✅ Filtered attendees based on search query
const filteredAttendees = computed(() => {
    console.log(props.attendees);
    if (!searchQuery.value) {
        return props.attendees;
    }
    return props.attendees.filter(attendee =>
        Object.entries(attendee)
            .filter(([key]) => !["created_at", "updated_at", "id", "event_id", "events_location", "mobile_number_format"].includes(key))
            .some(([key, value]) => {
                if (key === "gender") {
                    return value.toLowerCase().trim() === searchQuery.value.toLowerCase().trim(); // ✅ Exact match for gender
                }
                return value && value.toString().toLowerCase().includes(searchQuery.value.toLowerCase());
            })
    );
});

// ✅ Paginate filtered attendees
const paginatedAttendees = computed(() => {
    const start = (currentPage.value - 1) * itemsPerPage;
    return filteredAttendees.value.slice(start, start + itemsPerPage);
});

// ✅ Total pages
const totalPages = computed(() => {
    return Math.ceil(filteredAttendees.value.length / itemsPerPage);
});

// ✅ Navigate pages
const goToPage = (page) => {
    if (page >= 1 && page <= totalPages.value) {
        currentPage.value = page;
    }
};

// ✅ Export filtered data to CSV
const exportToCSV = () => {
    if (filteredAttendees.value.length === 0) {
        Swal.fire('No Data', 'No attendees found to export.', 'warning');
        return;
    }

    let csvContent = "data:text/csv;charset=utf-8,";

    // Add headers
    csvContent += columnHeaders.value.map(formatHeader).join(",") + "\n";

    // Add data rows
    filteredAttendees.value.forEach(attendee => {
        csvContent += columnHeaders.value.map(col => `"${attendee[col] || ''}"`).join(",") + "\n";
    });

    // Create a downloadable link
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", `attendees_${props.event.event_name}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
};

// ✅ Delete Attendee with Confirmation
const deleteAttendee = (attendeeId, eventId) => {
    Swal.fire({
        title: 'Are you sure?',
        text: 'This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('attendees.destroy', { attendee: attendeeId, event: eventId }), {
                onSuccess: () => {
                    Swal.fire('Deleted!', 'The attendee has been removed.', 'success');
                }
            });
        }
    });
};
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
                        <div class="flex justify-between mb-3">
                            <!-- ✅ Search Bar -->
                            <input
                                v-model="searchQuery"
                                type="text"
                                placeholder="Search attendees..."
                                class="w-full md:w-1/3 p-2 border rounded"
                            />
                            <!-- ✅ Export Button -->
                            <button 
                                @click="exportToCSV" 
                                class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-700"
                            >
                                <i class="fa-solid fa-file-csv"></i>
                            </button>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse border border-gray-300">
                                <thead class="bg-gray-200 sticky top-0">
                                    <tr>
                                        <th v-for="(col, index) in columnHeaders" :key="index" class="border border-gray-300 p-2 whitespace-nowrap">
                                            {{ formatHeader(col) }}
                                        </th>
                                        <th class="border border-gray-300 p-2">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(attendee, index) in paginatedAttendees" :key="index" class="text-left even:bg-gray-100">
                                        <td v-for="(col, index) in columnHeaders" :key="index" class="border border-gray-300 p-2 break-words">
                                            {{ attendee[col] }}
                                        </td>
                                        <td class="text-center content-center">
                                            <button class="btn btn-danger m-1" @click="deleteAttendee(attendee.id, event.id)">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- ✅ Pagination Controls -->
                        <div class="flex justify-between items-center mt-4">
                            <button 
                                @click="goToPage(currentPage - 1)" 
                                :disabled="currentPage === 1" 
                                class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400 disabled:opacity-50"
                            >
                                Previous
                            </button>
                            
                            <span class="text-gray-700">Page {{ currentPage }} of {{ totalPages }}</span>
                            
                            <button 
                                @click="goToPage(currentPage + 1)" 
                                :disabled="currentPage === totalPages" 
                                class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400 disabled:opacity-50"
                            >
                                Next
                            </button>
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
