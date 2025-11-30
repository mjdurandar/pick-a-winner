<script setup>
import Swal from 'sweetalert2';
import { ref, computed } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

// Props from Laravel
const props = defineProps({
    films: Array
});

// Track if we are editing a film
const isEditing = ref(false);
const page = usePage();
const user = computed(() => page.props.auth.user || null);
const userRole = computed(() => user.value?.role);

// Form state
const form = useForm({
    id: null,
    name: '',
    country: ''
});

// Open Modal for Creating a New Film
const openCreateModal = () => {
    isEditing.value = false;
    form.reset(); // Clear form
    let modalElement = new bootstrap.Modal(document.getElementById('createFilmModal'));
    modalElement.show();
};

// Open Modal for Editing an Existing Film
const openEditModal = (film) => {
    isEditing.value = true;
    form.id = film.id;
    form.name = film.name;
    form.country = film.country || '';

    let modalElement = new bootstrap.Modal(document.getElementById('createFilmModal'));
    modalElement.show();
};

// Submit the form (Create or Update)
const saveFilm = () => {
    if (isEditing.value) {
        form.put(route('films.update', form.id), {
            onSuccess: () => {
                let modalElement = bootstrap.Modal.getInstance(document.getElementById('createFilmModal'));
                modalElement.hide();
                Swal.fire('Updated!', 'Film has been updated.', 'success');
                form.reset();
            },
            onError: (errors) => {
                Swal.fire('Error!', 'There was an issue updating the film.', 'error');
                console.log(errors);
            }
        });
    } else {
        form.post(route('films.store'), {
            onSuccess: () => {
                let modalElement = bootstrap.Modal.getInstance(document.getElementById('createFilmModal'));
                modalElement.hide();
                Swal.fire('Created!', 'Film has been created.', 'success');
                form.reset();
            },
            onError: (errors) => {
                Swal.fire('Error!', 'There was an issue creating the film.', 'error');
                console.log(errors);
            }
        });
    }
};

// Delete Film
const deleteFilm = (filmId) => {
    Swal.fire({
        title: 'Are you sure?',
        text: "You won't be able to revert this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('films.destroy', filmId), {
                onSuccess: () => {
                    Swal.fire('Deleted!', 'Film has been deleted.', 'success');
                },
                onError: () => {
                    Swal.fire('Error!', 'There was an issue deleting the film.', 'error');
                }
            });
        }
    });
};
</script>

<template>
    <Head title="Films" />

    <AuthenticatedLayout>
        <template #header>
            <div class="d-flex justify-content-between align-items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Films</h2>
                <button 
                    v-if="userRole === 'admin'"
                    @click="openCreateModal" 
                    class="btn" 
                    style="background-color: #16C3D9; color: white;"
                >
                    <i class="fa-solid fa-plus me-2"></i>Create Film
                </button>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="row">
                            <div v-for="film in props.films" :key="film.id" class="col-md-4 mb-4">
                                <div class="card">
                                    <div class="card-body">
                                        <h5 class="card-title">{{ film.name }}</h5>
                                        <p class="text-muted mb-1" v-if="film.country">
                                            <small>{{ film.country }}</small>
                                        </p>
                                        <p class="text-muted mb-2">
                                            <small>{{ film.events_count || 0 }} Event(s)</small>
                                        </p>
                                        <div class="d-flex justify-content-end mt-3" v-if="userRole === 'admin'">
                                            <button 
                                                @click="openEditModal(film)" 
                                                class="btn btn-sm me-2" 
                                                style="background-color: #16C3D9; color: white;"
                                            >
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </button>
                                            <button 
                                                @click="deleteFilm(film.id)" 
                                                class="btn btn-sm" 
                                                style="background-color: #FF5349; color: white;"
                                            >
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-if="props.films.length === 0" class="text-center py-5">
                            <p class="text-gray-500">No films found. Create your first film!</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bootstrap Modal for Create/Edit -->
        <div class="modal fade" id="createFilmModal" tabindex="-1" aria-labelledby="createFilmModalLabel">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="createFilmModalLabel">{{ isEditing ? 'Edit Film' : 'Create Film' }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form @submit.prevent="saveFilm">
                            <div class="mb-3">
                                <label class="form-label">Film Name</label>
                                <input 
                                    v-model="form.name" 
                                    type="text" 
                                    class="form-control" 
                                    required 
                                    maxlength="255"
                                    :class="{ 'is-invalid': form.errors.name }"
                                />
                                <div v-if="form.errors.name" class="invalid-feedback">
                                    {{ form.errors.name }}
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Country</label>
                                <select v-model="form.country" class="form-select" :class="{ 'is-invalid': form.errors.country }">
                                    <option value="">Select a country (optional)</option>
                                    <option value="AUSTRALIA & NEW ZEALAND">AUSTRALIA & NEW ZEALAND</option>
                                    <option value="USA & CANADA">USA & CANADA</option>
                                    <option value="USA">USA</option>
                                    <option value="Canada">Canada</option>
                                    <option value="UK">UK</option>
                                    <option value="Australia">Australia</option>
                                    <option value="New Zealand">New Zealand</option>
                                    <option value="Germany">Germany</option>
                                </select>
                                <div v-if="form.errors.country" class="invalid-feedback">
                                    {{ form.errors.country }}
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn" style="background-color: #16C3D9; color: white;" :disabled="form.processing">
                                    <span v-if="form.processing" class="spinner-border spinner-border-sm me-2"></span>
                                    {{ isEditing ? 'Update' : 'Create' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>


