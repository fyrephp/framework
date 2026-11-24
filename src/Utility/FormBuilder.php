<?php
declare(strict_types=1);

namespace Fyre\Utility;

use BadMethodCallException;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;

use function array_map;
use function array_shift;
use function array_values;
use function in_array;
use function is_array;
use function sprintf;
use function strval;

/**
 * Provides form builder utilities.
 *
 * This class generates HTML strings for common form elements and delegates escaping/attribute serialization to
 * HtmlHelper.
 *
 * @method string checkbox(string|null $name = null, array<mixed> $attributes = [])
 * @method string color(string|null $name = null, array<mixed> $attributes = [])
 * @method string date(string|null $name = null, array<mixed> $attributes = [])
 * @method string datetime(string|null $name = null, array<mixed> $attributes = [])
 * @method string email(string|null $name = null, array<mixed> $attributes = [])
 * @method string file(string|null $name = null, array<mixed> $attributes = [])
 * @method string hidden(string|null $name = null, array<mixed> $attributes = [])
 * @method string image(string|null $name = null, array<mixed> $attributes = [])
 * @method string month(string|null $name = null, array<mixed> $attributes = [])
 * @method string number(string|null $name = null, array<mixed> $attributes = [])
 * @method string password(string|null $name = null, array<mixed> $attributes = [])
 * @method string radio(string|null $name = null, array<mixed> $attributes = [])
 * @method string range(string|null $name = null, array<mixed> $attributes = [])
 * @method string reset(string|null $name = null, array<mixed> $attributes = [])
 * @method string search(string|null $name = null, array<mixed> $attributes = [])
 * @method string submit(string|null $name = null, array<mixed> $attributes = [])
 * @method string tel(string|null $name = null, array<mixed> $attributes = [])
 * @method string text(string|null $name = null, array<mixed> $attributes = [])
 * @method string time(string|null $name = null, array<mixed> $attributes = [])
 * @method string url(string|null $name = null, array<mixed> $attributes = [])
 * @method string week(string|null $name = null, array<mixed> $attributes = [])
 */
class FormBuilder
{
    use DebugTrait;
    use MacroTrait;

    protected const INPUT_TYPES = [
        'checkbox',
        'color',
        'date',
        'datetime-local',
        'email',
        'file',
        'hidden',
        'image',
        'month',
        'number',
        'password',
        'radio',
        'range',
        'reset',
        'search',
        'submit',
        'tel',
        'text',
        'time',
        'url',
        'week',
    ];

    /**
     * Constructs a FormBuilder.
     *
     * @param HtmlHelper $html The HtmlHelper.
     */
    public function __construct(
        protected HtmlHelper $html
    ) {}

    /**
     * Renders an input type element.
     *
     * Note: The `datetime` alias maps to the `datetime-local` input type.
     *
     * @param string $type The input type.
     * @param array<mixed> $arguments The arguments to pass to the input method.
     * @return string The input HTML.
     *
     * @throws BadMethodCallException If the input type is not valid.
     */
    public function __call(string $type, array $arguments): string
    {
        if ($type === 'datetime') {
            $type = 'datetime-local';
        }

        if (!in_array($type, static::INPUT_TYPES, true)) {
            throw new BadMethodCallException(sprintf(
                'Input type `%s` is not valid.',
                $type
            ));
        }

        $name = array_shift($arguments);
        $attributes = array_shift($arguments) ?? [];
        $attributes['type'] = $type;

        return $this->input($name, $attributes);
    }

    /**
     * Renders a button element.
     *
     * @param string $content The button content.
     * @param array<mixed> $attributes The button attributes.
     * @param bool $escape Whether the button content is escaped.
     * @return string The button HTML.
     */
    public function button(string $content = '', array $attributes = [], bool $escape = true): string
    {
        $attributes['type'] ??= 'button';

        if ($escape) {
            $content = $this->html->escape($content);
        }

        return '<button'.$this->html->attributes($attributes).'>'.$content.'</button>';
    }

    /**
     * Renders a form close tag.
     *
     * @return string The form close HTML.
     */
    public function close(): string
    {
        return '</form>';
    }

    /**
     * Renders a fieldset close tag.
     *
     * @return string The fieldset close HTML.
     */
    public function fieldsetClose(): string
    {
        return '</fieldset>';
    }

    /**
     * Renders a fieldset open tag.
     *
     * @param array<mixed> $attributes The fieldset attributes.
     * @return string The fieldset open HTML.
     */
    public function fieldsetOpen(array $attributes = []): string
    {
        return '<fieldset'.$this->html->attributes($attributes).'>';
    }

    /**
     * Renders an input element.
     *
     * @param string|null $name The input name.
     * @param array<mixed> $attributes The input attributes.
     * @return string The input HTML.
     */
    public function input(string|null $name = null, array $attributes = []): string
    {
        if ($name !== null) {
            $attributes['name'] ??= $name;
        }

        $attributes['type'] ??= 'text';

        return '<input'.$this->html->attributes($attributes).' />';
    }

    /**
     * Renders a label element.
     *
     * @param string $content The label content.
     * @param array<mixed> $attributes The label attributes.
     * @param bool $escape Whether the label content is escaped.
     * @return string The label HTML.
     */
    public function label(string $content = '', array $attributes = [], bool $escape = true): string
    {
        if ($escape) {
            $content = $this->html->escape($content);
        }

        return '<label'.$this->html->attributes($attributes).'>'.$content.'</label>';
    }

    /**
     * Renders a legend element.
     *
     * @param string $content The legend content.
     * @param array<mixed> $attributes The legend attributes.
     * @param bool $escape Whether the legend content is escaped.
     * @return string The legend HTML.
     */
    public function legend(string $content = '', array $attributes = [], bool $escape = true): string
    {
        if ($escape) {
            $content = $this->html->escape($content);
        }

        return '<legend'.$this->html->attributes($attributes).'>'.$content.'</legend>';
    }

    /**
     * Renders a form open tag.
     *
     * @param string|null $action The form action.
     * @param array<mixed> $attributes The form attributes.
     * @return string The form open HTML.
     */
    public function open(string|null $action = null, array $attributes = []): string
    {
        if ($action !== null) {
            $attributes['action'] ??= $action;
        }

        $attributes['method'] ??= 'post';
        $attributes['accept-charset'] ??= $this->html->getCharset();

        return '<form'.$this->html->attributes($attributes).'>';
    }

    /**
     * Renders a multipart form open tag.
     *
     * @param string|null $action The form action.
     * @param array<mixed> $attributes The form attributes.
     * @return string The form open HTML.
     */
    public function openMultipart(string|null $action = null, array $attributes = []): string
    {
        $attributes['enctype'] = 'multipart/form-data';

        return $this->open($action, $attributes);
    }

    /**
     * Renders a select element.
     *
     * @param string|null $name The select name.
     * @param array<mixed> $attributes The select attributes.
     * @param array<mixed> $options The select options.
     * @return string The select HTML.
     */
    public function select(string|null $name = null, array $attributes = [], array $options = []): string
    {
        if ($name !== null) {
            $attributes['name'] ??= $name;
        }

        $selected = $attributes['value'] ?? [];
        unset($attributes['value']);

        if (!is_array($selected)) {
            $selected = [$selected];
        }
        $selected = array_values($selected) |> array_map(strval(...), ?);

        $html = '<select'.$this->html->attributes($attributes).'>';
        $html .= $this->buildOptions($options, $selected);
        $html .= '</select>';

        return $html;
    }

    /**
     * Renders a multiple select element.
     *
     * @param string|null $name The select name.
     * @param array<mixed> $attributes The select attributes.
     * @param array<mixed> $options The select options.
     * @return string The select HTML.
     */
    public function selectMulti(string|null $name = null, array $attributes = [], array $options = []): string
    {
        $attributes['multiple'] ??= true;

        return $this->select($name, $attributes, $options);
    }

    /**
     * Renders a textarea element.
     *
     * @param string|null $name The textarea name.
     * @param array<mixed> $attributes The textarea attributes.
     * @return string The textarea HTML.
     */
    public function textarea(string|null $name = null, array $attributes = []): string
    {
        if ($name !== null) {
            $attributes['name'] ??= $name;
        }

        $value = (string) ($attributes['value'] ?? '');
        unset($attributes['value']);

        return '<textarea'.$this->html->attributes($attributes).'>'.$this->html->escape($value).'</textarea>';
    }

    /**
     * Renders select options.
     *
     * @param array<mixed> $options The select options.
     * @param mixed[] $selected The selected values.
     * @return string The options HTML.
     */
    protected function buildOptions(array $options, array $selected): string
    {
        $html = '';

        foreach ($options as $value => $option) {
            if (!is_array($option)) {
                $option = [
                    'label' => $option,
                ];
            }

            if (isset($option['children'])) {
                $children = $option['children'];
                unset($option['children']);

                $html .= '<optgroup'.$this->html->attributes($option).'>';
                $html .= $this->buildOptions($children, $selected);
                $html .= '</optgroup>';
            } else {
                $option['value'] ??= $value;
                $option['value'] = (string) $option['value'];
                $label = $option['label'] ?? $option['value'];
                unset($option['label']);

                if (in_array($option['value'], $selected, true)) {
                    $option['selected'] = true;
                }

                $html .= '<option'.$this->html->attributes($option).'>'.$this->html->escape($label).'</option>';
            }
        }

        return $html;
    }
}
