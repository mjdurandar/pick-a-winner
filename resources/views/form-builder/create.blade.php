<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Create Sign Up Form for ') . $event->name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 col-md-5">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                <form action="{{ route('form-builder.store', $event->id) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div id="fields-container">
                        <div class="field-group mb-4">
                            <label for="field_name_0" class="block text-gray-700 text-sm font-bold mb-2">Banner Image</label>
                            <input type="file" id="banner_image" name="banner_image" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none">
                        </div>
                        <div class="field-group mb-4">
                            <label for="email" class="block text-gray-700 text-sm font-bold mb-2">Email</label>
                            <input type="email" id="email" name="email" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none" disabled>
                        </div>
                        <div class="field-group mb-4">
                            <label for="first_name" class="block text-gray-700 text-sm font-bold mb-2">First Name</label>
                            <input type="text" id="first_name" name="first_name" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none" disabled>
                        </div>
                        <div class="field-group mb-4">
                            <label for="last_name" class="block text-gray-700 text-sm font-bold mb-2">Last Name</label>
                            <input type="text" id="last_name" name="last_name" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none" disabled>
                        </div>
                        <div class="field-group mb-4">
                            <label for="gender" class="block text-gray-700 text-sm font-bold mb-2">Gender</label>
                            <div class="flex">
                                <select id="gender" name="gender" class="block appearance-none w-full bg-white border border-gray-400 hover:border-gray-500 px-4 py-2 pr-8 rounded shadow leading-tight focus:outline-none focus:shadow-outline">
                                    <option value="">Select Gender</option>
                                </select>
                                <button id="add-gender-btn" class="btn btn-success ml-2"><i class="fa-solid fa-plus"></i></button>
                            </div>
                        </div>

                        <!-- Hidden input field to capture new gender input -->
                        <div id="new-gender-container" class="mt-2 hidden">
                            <input type="text" id="new-gender" class="border border-gray-400 px-4 py-2 rounded shadow" placeholder="Enter new gender" />
                            <button id="submit-gender-btn" class="btn btn-primary ml-2">Add</button>
                        </div>
                        
                        <!-- Hidden input to track all genders -->
                        <input type="hidden" id="all-genders" name="all_genders" value="">

                        <div class="field-group mb-4">
                            <label for="age" class="block text-gray-700 text-sm font-bold mb-2">Age</label>
                            <input type="number" id="age" name="age" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none" disabled>
                        </div>
                        <div class="field-group mb-4">
                            <label for="event_location" class="block text-gray-700 text-sm font-bold mb-2">Event Location</label>
                            <input type="text" id="event_location" name="event_location" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none" disabled>
                        </div>
                        <div class="field-group mb-4">
                            <label for="field_name_0" class="block text-gray-700 text-sm font-bold mb-2">Field Name</label>
                            <input type="text" id="field_name_0" name="fields[0][name]" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none" required>

                            <label for="field_type_0" class="block text-gray-700 text-sm font-bold mb-2">Field Type</label>
                            <select id="field_type_0" name="fields[0][type]" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none field-type-select" data-index="0">
                                <option value="text">Text</option>
                                <option value="email">Email</option>
                                <option value="number">Number</option>
                                <option value="dropdown">Dropdown</option>
                            </select>

                            <div class="mt-2 field-options-container" id="field_options_container_0" style="display: none;">
                                <label for="field_options_0" class="block text-gray-700 text-sm font-bold mb-2">Dropdown Options (comma-separated)</label>
                                <input type="text" id="field_options_0" name="fields[0][options]" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none">
                            </div>
                        </div>
                    </div>

                    <button type="button" id="add-field" class="btn btn-secondary mb-4">Add Another Field</button>

                    <div class="flex items-center justify-center">
                        <button type="submit" class="btn btn-primary">
                            Save Sign Up Form
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        let fieldCount = 1;
        let gendersArray = [];

        document.getElementById('add-field').addEventListener('click', function () {
            const fieldsContainer = document.getElementById('fields-container');

            const fieldGroup = document.createElement('div');
            fieldGroup.classList.add('field-group', 'mb-4');
            fieldGroup.innerHTML = `
                <label for="field_name_${fieldCount}" class="block text-gray-700 text-sm font-bold mb-2">Field Name</label>
                <input type="text" id="field_name_${fieldCount}" name="fields[${fieldCount}][name]" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none" required>

                <label for="field_type_${fieldCount}" class="block text-gray-700 text-sm font-bold mb-2">Field Type</label>
                <select id="field_type_${fieldCount}" name="fields[${fieldCount}][type]" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none field-type-select" data-index="${fieldCount}">
                    <option value="text">Text</option>
                    <option value="email">Email</option>
                    <option value="number">Number</option>
                    <option value="dropdown">Dropdown</option>
                </select>

                <div class="mt-2 field-options-container" id="field_options_container_${fieldCount}" style="display: none;">
                    <label for="field_options_${fieldCount}" class="block text-gray-700 text-sm font-bold mb-2">Dropdown Options (comma-separated)</label>
                    <input type="text" id="field_options_${fieldCount}" name="fields[${fieldCount}][options]" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none">
                </div>
            `;

            fieldsContainer.appendChild(fieldGroup);

            document.getElementById(`field_type_${fieldCount}`).addEventListener('change', function () {
                const index = this.getAttribute('data-index');
                const optionsContainer = document.getElementById(`field_options_container_${index}`);
                if (this.value === 'dropdown') {
                    optionsContainer.style.display = 'block';
                } else {
                    optionsContainer.style.display = 'none';
                }
            });

            fieldCount++;
        });

        // Event listener for adding a gender
        document.getElementById('add-gender-btn').addEventListener('click', function(event) {
            event.preventDefault();
            document.getElementById('new-gender-container').classList.remove('hidden');
        });

        document.getElementById('submit-gender-btn').addEventListener('click', function(event) {
            event.preventDefault();
            const newGender = document.getElementById('new-gender').value.trim();

            if (newGender) {
                // Add the new gender to the genders array
                gendersArray.push(newGender);

                // Also add the new gender to the dropdown (optional)
                const genderSelect = document.getElementById('gender');
                const newOption = document.createElement('option');
                newOption.value = newGender;
                newOption.textContent = newGender;
                genderSelect.appendChild(newOption);

                // Clear the input and hide the add gender container
                document.getElementById('new-gender-container').classList.add('hidden');
                document.getElementById('new-gender').value = '';
            }

            // Update the hidden input with the current array of genders
            document.getElementById('all-genders').value = gendersArray.join(',');
        });

        // Event listener for selecting an existing gender
        document.getElementById('gender').addEventListener('change', function() {
            const selectedGender = this.value;
            if (selectedGender && !gendersArray.includes(selectedGender)) {
                gendersArray.push(selectedGender);
            }

            // Update the hidden input with the current array of genders
            document.getElementById('all-genders').value = gendersArray.join(',');
        });
    </script>
</x-app-layout>
<script>
    @if(session('success'))
        Swal.fire({
            title: 'Success!',
            text: "{{ session('success') }}",
            icon: 'success',
            confirmButtonText: 'OK'
        });
    @endif

    @if(session('error'))
        Swal.fire({
            title: 'Error!',
            text: "{{ session('error') }}",
            icon: 'error',
            confirmButtonText: 'OK'
        });
    @endif
</script>
