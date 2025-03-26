<script setup>
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';
import Swal from 'sweetalert2';
import { useForm } from '@inertiajs/vue3';

// ✅ Define Props to Receive Locations from Backend
const props = defineProps({
    event: Object,
    locations: Array
});

// ✅ Reactive Data
const searchQuery = ref(''); // ✅ Search query input
const copiedIndex = ref(null); // ✅ Index of copied location link
const showPasswordModal = ref(false);
const showEventPasswordModal = ref(false);
const showLocationModal = ref(false);
const isEditing = ref(false);
const selectedLocation = ref(null);
const newPassword = ref('');
const confirmPassword = ref('');
const eventNewPassword = ref('');
const eventConfirmPassword = ref('');

// Add new form for location creation
const locationForm = useForm({
    id: null,
    name: '',
    event_id: '',
    date: '',
});

// ✅ Computed Property to Filter Locations
const filteredLocations = computed(() => {
    if (!searchQuery.value) {
        return props.locations;
    }
    return props.locations.filter(location =>
        location.name.toLowerCase().includes(searchQuery.value.toLowerCase())
    );
});

const openPasswordModal = (location) => {
    selectedLocation.value = location;
    newPassword.value = '';
    confirmPassword.value = '';
    showPasswordModal.value = true;
    let modalElement = new bootstrap.Modal(document.getElementById('passwordModal'));
    modalElement.show();
};

const openEventPasswordModal = () => {
    eventNewPassword.value = '';
    eventConfirmPassword.value = '';
    showEventPasswordModal.value = true;
};

const generatePassword = (isEvent = false) => {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let password = '';
    for (let i = 0; i < 8; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    if (isEvent) {
        eventNewPassword.value = password;
        eventConfirmPassword.value = password;
    } else {
        newPassword.value = password;
        confirmPassword.value = password;
    }
};

// const updateEventPassword = async () => {
//     if (!eventNewPassword.value || !eventConfirmPassword.value) {
//         Swal.fire({
//             icon: 'error',
//             title: 'Error',
//             text: 'Please fill in all fields'
//         });
//         return;
//     }

//     if (eventNewPassword.value !== eventConfirmPassword.value) {
//         Swal.fire({
//             icon: 'error',
//             title: 'Error',
//             text: 'Passwords do not match'
//         });
//         return;
//     }

//     try {
//         await router.put(route('event.updatePassword', props.event.id), {
//             password: eventNewPassword.value
//         });

//         Swal.fire({
//             icon: 'success',
//             title: 'Success!',
//             text: 'Event password updated successfully',
//             timer: 1500,
//             showConfirmButton: false
//         });
//         showEventPasswordModal.value = false;
//     } catch (error) {
//         Swal.fire({
//             icon: 'error',
//             title: 'Error',
//             text: 'Failed to update password. Please try again.'
//         });
//     }
// };

const updatePassword = async () => {
    if (!newPassword.value || !confirmPassword.value) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Please fill in all fields'
        });
        return;
    }

    if (newPassword.value !== confirmPassword.value) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Passwords do not match'
        });
        return;
    }

    try {
        await router.put(route('location.updatePassword', selectedLocation.value.id), {
            password: newPassword.value
        });

        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: 'Password updated successfully',
            timer: 1500,
            showConfirmButton: false
        });
        showPasswordModal.value = false;
    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Failed to update password. Please try again.'
        });
    }
};

const copyPassword = (location, index) => {
    navigator.clipboard.writeText(location.password);
    copiedIndex.value = index;
    // Swal.fire({
    //     icon: 'success',
    //     title: 'Copied!',
    //     text: 'Password copied to clipboard',
    //     timer: 1500,
    //     showConfirmButton: false
    // });
    // setTimeout(() => {
    //     copiedIndex.value = null;
    // }, 2000);
};

const copyEventPassword = () => {
    navigator.clipboard.writeText(props.event.password);
    Swal.fire({
        icon: 'success',
        title: 'Copied!',
        text: 'Event password copied to clipboard',
        timer: 1500,
        showConfirmButton: false
    });
};

const allLocationsPage = () => {
    router.get(route('pickawinner.alllocation', { event: props.event.id }));
};

const openPasswordLocation = () => {

}

// Function to open location creation modal
const openLocationModal = (location = null) => {
    locationForm.reset();
    if (location) {
        isEditing.value = true;
        locationForm.id = location.id;
        locationForm.name = location.name;
        locationForm.date = location.date;
        locationForm.event_id = props.event.id;
    } else {
        isEditing.value = false;
        locationForm.event_id = props.event.id;
    }
    showLocationModal.value = true;
    let modalElement = new bootstrap.Modal(document.getElementById('createLocationModal'));
    modalElement.show();
};

// Function to save location
const saveLocation = () => {
    if (!locationForm.name || !locationForm.date) {
        Swal.fire('Error', 'Location name and date are required!', 'error');
        return;
    }

    if (isEditing.value) {
        router.put(route('location.update', locationForm.id), locationForm, {
            onSuccess: () => {
                closeLocationModal();
                Swal.fire('Success!', 'Location has been updated.', 'success');
                locationForm.reset();
                router.reload();
            },
            onError: (errors) => {
                Swal.fire('Error!', 'There was an issue updating the location.', 'error');
            }
        });
    } else {
        router.post(route('location.store'), locationForm, {
            onSuccess: () => {
                closeLocationModal();
                Swal.fire('Success!', 'Location has been created.', 'success');
                locationForm.reset();
                router.reload();
            },
            onError: (errors) => {
                Swal.fire('Error!', 'There was an issue creating the location.', 'error');
            }
        });
    }
};

// Function to delete location
const deleteLocation = (location) => {
    Swal.fire({
        title: 'Are you sure?',
        text: "This action cannot be undone!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('location.destroy', location.id), {
                onSuccess: () => {
                    Swal.fire(
                        'Deleted!',
                        'Location has been deleted.',
                        'success'
                    );
                    router.reload();
                },
                onError: () => {
                    Swal.fire(
                        'Error!',
                        'There was an issue deleting the location.',
                        'error'
                    );
                }
            });
        }
    });
};

// Add function to handle modal close
const closeLocationModal = () => {
    showLocationModal.value = false;
    let modalElement = bootstrap.Modal.getInstance(document.getElementById('createLocationModal'));
    if (modalElement) {
        modalElement.hide();
        // Remove the backdrop
        const backdrop = document.querySelector('.modal-backdrop');
        if (backdrop) {
            backdrop.remove();
        }
        // Remove the modal-open class from body
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    }
};

// Add function to handle password modal close
const closePasswordModal = () => {
    showPasswordModal.value = false;
    let modalElement = bootstrap.Modal.getInstance(document.getElementById('passwordModal'));
    if (modalElement) {
        modalElement.hide();
        // Remove the backdrop
        const backdrop = document.querySelector('.modal-backdrop');
        if (backdrop) {
            backdrop.remove();
        }
        // Remove the modal-open class from body
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    }
};

</script>

<template>
    <Head title="Pick a Winner Page" />

    <AuthenticatedLayout>
        <div class="p-4">
            <div class="mx-auto max-w-3xl">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 text-center">
                        <div class="flex flex-col sm:flex-row justify-between items-center gap-4 mb-4">
                            <h3 class="text-lg font-semibold text-center sm:text-left">Locations Password for {{ event.event_name }}</h3>
                            <button 
                                class="w-full sm:w-auto bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-700"
                                @click="openLocationModal()"
                            >
                                Add Location
                            </button>
                        </div>
                        <!-- <div class="d-flex justify-content-between items-center mb-4">
                            <button class="btn btn-primary" @click="openEventPasswordModal">
                                <i class="fa-solid fa-key"></i> Event Password
                            </button>
                        </div> -->
                        <!-- ✅ Search Bar -->
                        <input 
                            v-model="searchQuery" 
                            type="text" 
                            placeholder="Search location..."
                            class="w-full border p-2 rounded mb-4 focus:ring focus:ring-blue-300"
                        />

                        <!-- ✅ Locations List -->
                        <div v-if="filteredLocations.length > 0" class="space-y-2">
                            <div 
                                v-for="(location, index) in filteredLocations" 
                                :key="index" 
                                class="flex items-center space-x-2 w-full"
                            >
                                <div class="bg-blue-500 text-white px-4 py-2 rounded w-full text-left truncate hover:bg-blue-700">
                                    {{ location.name }}
                                </div>
                                <!-- Edit Button -->
                                <button 
                                    @click="openLocationModal(location)"
                                    class="bg-yellow-500 text-white px-3 py-2 rounded hover:bg-yellow-600 transition"
                                    title="Edit Location"
                                >
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                                <!-- Delete Button -->
                                <button 
                                    @click="deleteLocation(location)"
                                    class="bg-red-500 text-white px-3 py-2 rounded hover:bg-red-600 transition"
                                    title="Delete Location"
                                >
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                                <!-- Password Button -->
                                <button 
                                    @click="openPasswordModal(location)"
                                    class="bg-green-300 text-gray-600 px-3 py-2 rounded hover:bg-gray-400 transition"
                                    title="View Password"
                                >
                                    <i class="fa-solid fa-key"></i>
                                </button>
                                <!-- Copy Password Button -->
                                <button 
                                    @click="copyPassword(location, index)"
                                    class="bg-gray-300 text-gray-600 px-3 py-2 rounded hover:bg-gray-400 transition"
                                    title="Copy Password"
                                >   
                                    <i :class="copiedIndex === index ? 'fa-solid fa-check text-green-600' : 'fa-solid fa-copy'"></i>
                                </button>
                            </div>
                        </div>

                        <!-- ✅ If No Locations Found -->
                        <div v-else>
                            <p class="text-gray-600 mt-3">No locations found.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Location Password Modal -->
        <div class="modal fade" id="passwordModal" tabindex="-1" aria-labelledby="passwordModalLabel" @hidden.bs.modal="closePasswordModal">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="passwordModalLabel">Location Password - {{ selectedLocation?.name }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <div class="input-group">
                                <input type="text" :value="selectedLocation?.password" class="form-control" readonly>
                                <button class="btn btn-outline-secondary" @click="copyPassword(selectedLocation, -1)">
                                    <i class="fa-solid fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="text" v-model="newPassword" class="form-control" placeholder="Enter new password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <input type="text" v-model="confirmPassword" class="form-control" placeholder="Confirm new password">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" @click="generatePassword(false)">
                            <i class="fa-solid fa-random"></i> Generate Password
                        </button>
                        <button type="button" class="btn btn-primary" @click="updatePassword">Save Changes</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Event Password Modal -->
        <!-- <div v-if="showEventPasswordModal" class="modal fade show" style="display: block;">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Event Password - {{ event.event_name }}</h5>
                        <button type="button" class="btn-close" @click="showEventPasswordModal = false"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <div class="input-group">
                                <input type="text" :value="event.password" class="form-control" readonly>
                                <button class="btn btn-outline-secondary" @click="copyEventPassword">
                                    <i class="fa-solid fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="text" v-model="eventNewPassword" class="form-control" placeholder="Enter new password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <input type="text" v-model="eventConfirmPassword" class="form-control" placeholder="Confirm new password">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" @click="generatePassword(true)">
                            <i class="fa-solid fa-random"></i> Generate Password
                        </button>
                        <button type="button" class="btn btn-primary" @click="updateEventPassword">Save Changes</button>
                    </div>
                </div>
            </div>
        </div> -->

        <!-- Add/Edit Location Modal -->
        <div class="modal fade" id="createLocationModal" tabindex="-1" aria-labelledby="createLocationModalLabel" @hidden.bs.modal="closeLocationModal">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="createLocationModalLabel">{{ isEditing ? 'Edit Location' : 'Create New Location' }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form @submit.prevent="saveLocation">
                            <div class="mb-3">
                                <label class="form-label">Location Name</label>
                                <input 
                                    v-model="locationForm.name" 
                                    type="text" 
                                    class="form-control" 
                                    required 
                                />
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Date</label>
                                <input 
                                    v-model="locationForm.date" 
                                    type="date" 
                                    class="form-control" 
                                    required 
                                />
                            </div>

                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">{{ isEditing ? 'Update Location' : 'Save Location' }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
