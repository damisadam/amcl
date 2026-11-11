<?php

namespace App\Livewire\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/** Search, sort, filter and paginate for list screens. */
trait WithDataTable
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $sort = '';

    #[Url(except: 'desc')]
    public string $dir = 'desc';

    public int $perPage = 15;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updated($property): void
    {
        if (str_starts_with($property, 'filter')) {
            $this->resetPage();
        }
    }

    public function sortBy(string $field): void
    {
        $this->dir = $this->sort === $field && $this->dir === 'asc' ? 'desc' : 'asc';
        $this->sort = $field;
    }

    protected function applySort(Builder $q, string $default = 'id', array $allowed = []): Builder
    {
        $field = $this->sort !== '' && (! $allowed || in_array($this->sort, $allowed, true)) ? $this->sort : $default;

        return $q->orderBy($field, $this->dir === 'asc' ? 'asc' : 'desc');
    }

    protected function applySearch(Builder $q, array $columns): Builder
    {
        $term = trim($this->search);
        if ($term === '') {
            return $q;
        }

        return $q->where(function ($w) use ($columns, $term) {
            foreach ($columns as $c) {
                $w->orWhere($c, 'like', '%'.$term.'%');
            }
        });
    }
}
