<?php


namespace FireflyIII\Api\V1\Requests\Insight;


use Carbon\Carbon;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Bill\BillRepositoryInterface;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use FireflyIII\Repositories\Category\CategoryRepositoryInterface;
use FireflyIII\Repositories\Tag\TagRepositoryInterface;
use FireflyIII\Support\Request\ChecksLogin;
use FireflyIII\Support\Request\ConvertsDataTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;

/**
 * Class GenericRequest
 */
class GenericRequest extends FormRequest
{
    use ConvertsDataTypes, ChecksLogin;

    private Collection $accounts;
    private Collection $budgets;
    private Collection $categories;
    private Collection $bills;
    private Collection $tags;

    /**
     * @return Carbon
     */
    public function getStart(): Carbon
    {
        $date = $this->date('start');
        $date->startOfDay();

        return $date;
    }

    /**
     * @return Carbon
     */
    public function getEnd(): Carbon
    {
        $date = $this->date('end');
        $date->endOfDay();

        return $date;
    }

    /**
     *
     */
    private function parseBudgets(): void
    {
        if (null === $this->budgets) {
            $this->budgets = new Collection;
        }
        if (0 !== $this->budgets->count()) {
            return;
        }
        $repository = app(BudgetRepositoryInterface::class);
        $repository->setUser(auth()->user());
        $array = $this->get('budgets');
        if (is_array($array)) {
            foreach ($array as $budgetId) {
                $budgetId = (int)$budgetId;
                $budget   = $repository->findNull($budgetId);
                if (null !== $budgetId) {
                    $this->budgets->push($budget);
                }
            }
        }
    }

    /**
     * @return Collection
     */
    public function getBudgets(): Collection
    {
        $this->parseBudgets();

        return $this->budgets;
    }

    /**
     * @return Collection
     */
    public function getCategories(): Collection
    {
        $this->parseCategories();

        return $this->categories;
    }

    /**
     * @return Collection
     */
    public function getBills(): Collection
    {
        $this->parseBills();

        return $this->bills;
    }

    /**
     * @return Collection
     */
    public function getTags(): Collection
    {
        $this->parseTags();

        return $this->tags;
    }


    /**
     * @return Collection
     */
    public function getAssetAccounts(): Collection
    {
        $this->parseAccounts();
        $return = new Collection;
        /** @var Account $account */
        foreach ($this->accounts as $account) {
            $type = $account->accountType->type;
            if (in_array($type, [AccountType::ASSET, AccountType::LOAN, AccountType::DEBT, AccountType::MORTGAGE])) {
                $return->push($account);
            }
        }

        return $return;
    }

    /**
     * @return Collection
     */
    public function getExpenseAccounts(): Collection
    {
        $this->parseAccounts();
        $return = new Collection;
        /** @var Account $account */
        foreach ($this->accounts as $account) {
            $type = $account->accountType->type;
            if (in_array($type, [AccountType::EXPENSE])) {
                $return->push($account);
            }
        }

        return $return;
    }

    /**
     * @return Collection
     */
    public function getRevenueAccounts(): Collection
    {
        $this->parseAccounts();
        $return = new Collection;
        /** @var Account $account */
        foreach ($this->accounts as $account) {
            $type = $account->accountType->type;
            if (in_array($type, [AccountType::REVENUE])) {
                $return->push($account);
            }
        }

        return $return;
    }

    /**
     * Get all data from the request.
     *
     * @return array
     */
    public function getAll(): array
    {
        return [
            'start' => $this->date('start'),
            'end'   => $this->date('end'),
        ];
    }

    /**
     * The rules that the incoming request must be matched against.
     *
     * @return array
     */
    public function rules(): array
    {
        // this is cheating but it works:
        $this->accounts   = new Collection;
        $this->budgets    = new Collection;
        $this->categories = new Collection;
        $this->bills      = new Collection;
        $this->tags       = new Collection;

        return [
            'start' => 'required|date',
            'end'   => 'required|date|after:start',
        ];
    }

    /**
     *
     */
    private function parseAccounts(): void
    {
        if (null === $this->accounts) {
            $this->accounts = new Collection;
        }
        if (0 !== $this->accounts->count()) {
            return;
        }
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser(auth()->user());
        $array = $this->get('accounts');
        if (is_array($array)) {
            foreach ($array as $accountId) {
                $accountId = (int)$accountId;
                $account   = $repository->findNull($accountId);
                if (null !== $account) {
                    $this->accounts->push($account);
                }
            }
        }
    }

    /**
     *
     */
    private function parseBills(): void
    {
        if (null === $this->bills) {
            $this->bills = new Collection;
        }
        if (0 !== $this->bills->count()) {
            return;
        }
        $repository = app(BillRepositoryInterface::class);
        $repository->setUser(auth()->user());
        $array = $this->get('bills');
        if (is_array($array)) {
            foreach ($array as $billId) {
                $billId = (int)$billId;
                $bill   = $repository->find($billId);
                if (null !== $billId) {
                    $this->bills->push($bill);
                }
            }
        }
    }

    /**
     *
     */
    private function parseCategories(): void
    {
        if (null === $this->categories) {
            $this->categories = new Collection;
        }
        if (0 !== $this->categories->count()) {
            return;
        }
        $repository = app(CategoryRepositoryInterface::class);
        $repository->setUser(auth()->user());
        $array = $this->get('categories');
        if (is_array($array)) {
            foreach ($array as $categoryId) {
                $categoryId = (int)$categoryId;
                $category   = $repository->findNull($categoryId);
                if (null !== $categoryId) {
                    $this->categories->push($category);
                }
            }
        }
    }

    /**
     *
     */
    private function parseTags(): void
    {
        if (null === $this->tags) {
            $this->tags = new Collection;
        }
        if (0 !== $this->tags->count()) {
            return;
        }
        $repository = app(TagRepositoryInterface::class);
        $repository->setUser(auth()->user());
        $array = $this->get('tags');
        if (is_array($array)) {
            foreach ($array as $tagId) {
                $tagId = (int)$tagId;
                $tag   = $repository->findNull($tagId);
                if (null !== $tagId) {
                    $this->tags->push($tag);
                }
            }
        }
    }
}