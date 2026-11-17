{{-- Name, topic and category fields shared by the create and edit channel forms. --}}
@php($isVoice = isset($channel) && $channel->isVoice())

<x-auth.field name="name" label="Channel name" required
    maxlength="{{ \App\Models\Channel::MAX_NAME_LENGTH }}"
    autocomplete="off"
    :value="old('name', $channel->name ?? '')" />
@unless ($isVoice)
    <p class="-mt-3 mb-4 text-xs text-[#949ba4]">Lower case, spaces become dashes: “Off Topic” is saved as off-topic.</p>

    <div class="mb-4">
        <label for="topic" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-[#b5bac1]">Channel topic</label>
        <textarea id="topic" name="topic" rows="3" maxlength="{{ \App\Models\Channel::MAX_TOPIC_LENGTH }}"
            placeholder="Let everyone know how to use this channel"
            @error('topic') aria-invalid="true" aria-describedby="topic-error" @enderror
            class="w-full rounded bg-[#1e1f22] px-3 py-2 text-white outline-none focus:ring-2 focus:ring-[#5865f2]">{{ old('topic', $channel->topic ?? '') }}</textarea>
        @error('topic')
            <p id="topic-error" class="mt-1 text-sm text-red-400">{{ $message }}</p>
        @enderror
    </div>
@endunless

<div class="mb-4">
    <label for="category_id" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-[#b5bac1]">Category</label>
    @php($current = (string) old('category_id', $selectedCategory ?? ''))
    <select id="category_id" name="category_id"
        @error('category_id') aria-invalid="true" aria-describedby="category_id-error" @enderror
        class="w-full rounded bg-[#1e1f22] px-3 py-2 text-white outline-none focus:ring-2 focus:ring-[#5865f2]">
        <option value="">No category</option>
        @foreach ($categories as $category)
            <option value="{{ $category->id }}" @selected($current === (string) $category->id)>{{ $category->name }}</option>
        @endforeach
    </select>
    @error('category_id')
        <p id="category_id-error" class="mt-1 text-sm text-red-400">{{ $message }}</p>
    @enderror
</div>
