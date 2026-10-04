<x-mail::message>
# {{ $overdue ? 'A payment is overdue' : 'A payment is due soon' }}

Hi {{ $name }},

{{ $message }}

<x-mail::table>
| Order | Client | Amount | Due date |
| :---- | :----- | -----: | :------- |
| {{ $orderNumber }} | {{ $clientName }} | {{ $amount }} | {{ $dueDate }} |
</x-mail::table>

<x-mail::button :url="$url">
Open the order
</x-mail::button>

Thanks,<br>
SalesHub
</x-mail::message>
