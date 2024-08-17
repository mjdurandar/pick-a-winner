<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Sign Up for ') . $event->name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 col-md-5">
            @if($form->banner_image)
                <div class="mb-4">
                    <img src="{{ asset('storage/' . $form->banner_image) }}" alt="Banner Image" class="w-full h-auto">
                </div>
            @endif
        </div>
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 col-md-5">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                <form action="{{ route('form-builder.submit', $form->id) }}" method="POST">
                    @csrf
                    <div class="field-group mb-4">
                        <label for="email" class="block text-gray-700 text-sm font-bold mb-2">Email</label>
                        <input type="email" id="email" name="email" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none">
                    </div>
                    <div class="field-group mb-4">
                        <label for="first_name" class="block text-gray-700 text-sm font-bold mb-2">First Name</label>
                        <input type="text" id="first_name" name="first_name" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none">
                    </div>
                    <div class="field-group mb-4">
                        <label for="last_name" class="block text-gray-700 text-sm font-bold mb-2">Last Name</label>
                        <input type="text" id="last_name" name="last_name" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none">
                    </div>
                    <div class="field-group mb-4">
                        <label for="gender" class="block text-gray-700 text-sm font-bold mb-2">Gender</label>
                        <input type="gender" id="gender" name="gender" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none">
                    </div>
                    <div class="field-group mb-4">
                        <label for="age" class="block text-gray-700 text-sm font-bold mb-2">Age</label>
                        <input type="number" id="age" name="age" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none">
                    </div>
                    <div class="field-group mb-4">
                        <label for="event_location" class="block text-gray-700 text-sm font-bold mb-2">Event Location</label>
                        <input type="text" id="event_location" name="event_location" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none">
                    </div>
                    @foreach($form->fields as $field)
                        <div class="mb-4">
                            <label for="field_{{ $field->id }}" class="block text-gray-700 text-sm font-bold mb-2">
                                {{ $field->name }}
                            </label>

                            @if($field->type === 'text' || $field->type === 'email' || $field->type === 'number')
                                <input type="{{ $field->type }}" id="field_{{ $field->id }}" name="fields[{{ $field->id }}]" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none" required>
                            @elseif($field->type === 'dropdown')
                                <select id="field_{{ $field->id }}" name="fields[{{ $field->id }}]" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none">
                                    @foreach(explode(',', $field->options) as $option)
                                        <option value="{{ $option }}">{{ $option }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </div>
                    @endforeach

                    <div class="flex items-center justify-center">
                        <button type="submit" class="btn btn-primary">
                            Submit
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
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
