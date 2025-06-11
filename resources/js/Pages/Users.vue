<script setup>
import Swal from 'sweetalert2';
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

// Props from Laravel
defineProps({
    users: Array
});

// Track if we are editing a user
const isEditing = ref(false);

// Form state
const form = useForm({
    id: null,
    name: '',
    email: '',
    role: '',
});

// Open Modal for Editing an Existing User
const openEditModal = (user) => {
    isEditing.value = true; // ✅ Set editing mode
    form.id = user.id;
    form.name = user.name;
    form.email = user.email;
    form.role = user.role;

    let modalElement = new bootstrap.Modal(document.getElementById('editUserModal'));
    modalElement.show();
};

// Submit the form (Update)
const saveUser = () => {
    const data = new FormData();
    data.append('name', form.name);
    data.append('email', form.email);
    data.append('role', form.role);

    if (isEditing.value) {
        data.append('_method', 'PATCH'); // ✅ Use PATCH for updating
        router.post(route('users.update', form.id), data, {
            onSuccess: () => {
                let modalElement = bootstrap.Modal.getInstance(document.getElementById('editUserModal'));
                modalElement.hide();
                Swal.fire('Updated!', 'User has been updated.', 'success');
                form.reset();
                isEditing.value = false;
            },
            onError: (errors) => {
                Swal.fire('Error!', 'There was an issue updating the user.', 'error');
                console.log(errors);
            }
        });
    }
};

// Delete User
const deleteUser = (id) => {
    Swal.fire({
        title: 'Are you sure?',
        text: 'This action cannot be undone! All data related to this user will be deleted. To confirm, type DELETE below.',
        icon: 'warning',
        input: 'text', // Require user input
        inputPlaceholder: 'Type DELETE to confirm',
        showCancelButton: true,
        confirmButtonText: 'Delete User',
        cancelButtonText: 'Cancel',
        inputValidator: (value) => {
            if (value !== 'DELETE') {
                return 'You must type DELETE to confirm!';
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('users.destroy', id), {
                onSuccess: () => {
                    Swal.fire('Deleted!', 'User has been deleted.', 'success');
                }
            });
        }
    });
};
</script>

<template>
    <Head title="Users" />
    <AuthenticatedLayout>
        <template #header>
            <div class="d-flex justify-content-between align-items-center">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Users</h2>
            </div>
        </template>

        <div class="p-2 pb-5 pt-5">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">

                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse border border-gray-300">
                                <thead class="bg-gray-200 sticky top-0">
                                    <tr>
                                        <th class="border border-gray-300 p-2">Name</th>
                                        <th class="border border-gray-300 p-2">Email</th>
                                        <th class="border border-gray-300 p-2">Role</th>
                                        <th class="border border-gray-300 p-2">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(user, index) in users" :key="index" class="text-left even:bg-gray-100">
                                        <td class="border border-gray-300 p-2">{{ user.name }}</td>
                                        <td class="border border-gray-300 p-2">{{ user.email }}</td>
                                        <td class="border border-gray-300 p-2">{{ user.role }}</td>
                                        <td class="border border-gray-300 p-2 text-center">
                                            <button class="btn m-1" style="background-color: #16C3D9; color: white; cursor: pointer;" @click="openEditModal(user)">
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </button>
                                            <button class="btn m-1" style="background-color: #16C3D9; color: white; cursor: pointer;" @click="deleteUser(user.id)">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- Bootstrap Modal for Edit -->
        <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form @submit.prevent="saveUser">
                            <div class="mb-3">
                                <label class="form-label">Name</label>
                                <input v-model="form.name" type="text" class="form-control" required />
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input v-model="form.email" type="email" class="form-control" required />
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Role</label>
                                <select v-model="form.role" class="form-control" required>
                                    <option value="host">Host</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-success">
                                    Save
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
