<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Events') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <table class="min-w-full bg-white dark:bg-gray-800">
                        <thead>
                            <tr>
                                <th class="py-2 px-4 border-b-2 border-gray-300 dark:border-gray-700 text-left leading-tight">
                                    Event Name
                                </th>
                                <th class="py-2 px-4 border-b-2 border-gray-300 dark:border-gray-700 text-left leading-tight">
                                    Date
                                </th>
                                <th class="py-2 px-4 border-b-2 border-gray-300 dark:border-gray-700 text-left leading-tight">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($events as $event)
                                <tr>
                                    <td class="py-2 px-4 border-b border-gray-300 dark:border-gray-700">
                                        {{ $event->name }}
                                    </td>
                                    <td class="py-2 px-4 border-b border-gray-300 dark:border-gray-700">
                                        {{ $event->date }}
                                    </td>
                                    <td class="py-2 px-4 border-b border-gray-300 dark:border-gray-700">
                                        <a href="{{ route('attendees.show', $event->id) }}" class="btn btn-success">View</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
