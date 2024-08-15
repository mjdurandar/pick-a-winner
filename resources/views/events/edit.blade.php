<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Edit Your Event') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 col-md-5">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                
                <!-- Display the current banner image if it exists -->
                @if($event->image)
                    <div class="mb-4">
                        <img src="{{ asset('storage/' . $event->image) }}" alt="Banner Image" class="w-full h-auto">
                    </div>
                @endif

                <!-- Form to edit the event and upload a new banner image -->
                <form action="{{ route('events.update', [$event->id]) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <!-- Field to upload a new banner image -->
                    <div class="mb-4">
                        <label for="image" class="block text-gray-700 text-sm font-bold mb-2">Upload New Banner Image</label>
                        <input type="file" id="image" name="image" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none">
                        <p class="text-xs text-gray-500 mt-1">Leave empty if you don't want to change the banner image.</p>
                    </div>

                    <!-- Event Name -->
                    <div class="mb-4">
                        <label for="name" class="block text-gray-700 text-sm font-bold mb-2">Event Name</label>
                        <input type="text" id="name" name="name" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none" value="{{ old('name', $event->name) }}" required>
                    </div>

                    <!-- Event Date -->
                    <div class="mb-4">
                        <label for="date" class="block text-gray-700 text-sm font-bold mb-2">Event Date</label>
                        <input type="date" id="date" name="date" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none" value="{{ old('date', $event->date) }}" required>
                    </div>

                    <!-- Event Description -->
                    <div class="mb-4">
                        <label for="description" class="block text-gray-700 text-sm font-bold mb-2">Event Description</label>
                        <textarea id="description" name="description" rows="4" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none" placeholder="Enter event description">{{ old('description', $event->description) }}</textarea>
                    </div>

                    <div class="flex items-center justify-center">
                        <button type="submit" class="btn btn-primary">
                            Update Event
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
