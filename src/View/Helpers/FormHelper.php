<?php
declare(strict_types=1);

namespace Fyre\View\Helpers;

use BackedEnum;
use BadMethodCallException;
use Fyre\Core\Container;
use Fyre\Core\Lang;
use Fyre\DB\TypeParser;
use Fyre\DB\Types\DateTimeType;
use Fyre\Form\Form;
use Fyre\ORM\Entity;
use Fyre\Security\CsrfProtection;
use Fyre\Utility\Arr;
use Fyre\Utility\EnumHelper;
use Fyre\Utility\EnumLabelInterface;
use Fyre\Utility\FormBuilder;
use Fyre\Utility\Inflector;
use Fyre\View\Form\Context;
use Fyre\View\Form\EntityContext;
use Fyre\View\Form\FormContext;
use Fyre\View\Form\NullContext;
use Fyre\View\Helper;
use Fyre\View\View;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

use function array_map;
use function array_pop;
use function array_shift;
use function assert;
use function class_parents;
use function explode;
use function in_array;
use function is_array;
use function is_subclass_of;
use function preg_replace;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function trim;

/**
 * Generates HTML forms for views.
 *
 * Resolves a form context from the provided item (or falls back to a null context), derives
 * field metadata such as required/constraints, and injects CSRF fields when CSRF protection
 * is enabled on the request.
 */
class FormHelper extends Helper
{
    protected const INPUT_TYPES = [
        'checkbox',
        'color',
        'date',
        'datetime',
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
        'select',
        'selectMulti',
        'submit',
        'tel',
        'text',
        'textarea',
        'time',
        'url',
        'week',
    ];

    /**
     * @var array<class-string, class-string<Context>>
     */
    protected static array $contextMap = [
        Entity::class => EntityContext::class,
        Form::class => FormContext::class,
    ];

    protected static Context $nullContext;

    protected Context|null $context = null;

    protected CsrfProtection $csrfProtection;

    protected string|null $idPrefix = null;

    protected ServerRequestInterface $request;

    /**
     * Constructs a FormHelper.
     *
     * @param Container $container The Container.
     * @param FormBuilder $formBuilder The FormBuilder.
     * @param TypeParser $typeParser The TypeParser.
     * @param Lang $lang The Lang.
     * @param Inflector $inflector The Inflector.
     * @param View $view The View.
     * @param array<string, mixed> $options The helper options.
     */
    public function __construct(
        protected Container $container,
        protected FormBuilder $formBuilder,
        protected TypeParser $typeParser,
        protected Lang $lang,
        protected Inflector $inflector,
        View $view,
        array $options = []
    ) {
        parent::__construct($view, $options);

        $this->request = $this->view->getRequest();
    }

    /**
     * Renders a button element.
     *
     * @param string $content The button content.
     * @param array<mixed> $attributes The button attributes.
     * @param bool $escape Whether to escape the button content.
     * @return string The button HTML.
     */
    public function button(string $content = '', array $attributes = [], bool $escape = true): string
    {
        return $this->formBuilder->button($content, $attributes, $escape);
    }

    /**
     * Renders a checkbox field.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The checkbox attributes.
     * @param bool $hiddenField Whether to render a hidden field.
     * @return string The checkbox HTML.
     */
    public function checkbox(string $key, array $attributes = [], bool $hiddenField = true): string
    {
        $context = $this->getContext();
        $parser = $this->typeParser->use('boolean');

        $attributes['id'] ??= $this->getId($key);
        $attributes['name'] ??= static::getName($key);
        $attributes['type'] ??= 'checkbox';
        $attributes['value'] ??= 1;
        $attributes['checked'] ??= $this->getValue($key, $attributes);
        $attributes['required'] ??= $context->isRequired($key);

        $attributes['checked'] = $parser->parse($attributes['checked']);

        $result = '';

        if ($hiddenField && $attributes['name'] !== false) {
            $result .= $this->formBuilder->hidden(null, [
                'name' => $attributes['name'],
                'value' => 0,
            ]);
        }

        $result .= static::cleanAttributes($attributes)
            |> $this->formBuilder->input(
                attributes: ?
            );

        return $result;
    }

    /**
     * Renders a form close tag.
     *
     * @return string The form close HTML.
     */
    public function close(): string
    {
        $this->context = null;
        $this->idPrefix = null;

        return $this->formBuilder->close();
    }

    /**
     * Renders a color input.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The input attributes.
     * @return string The color input HTML.
     */
    public function color(string $key, array $attributes = []): string
    {
        $attributes['type'] ??= 'color';
        $attributes['required'] = false;

        return $this->text($key, $attributes);
    }

    /**
     * Renders a date input.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The input attributes.
     * @return string The date input HTML.
     */
    public function date(string $key, array $attributes = []): string
    {
        $context = $this->getContext();
        $parser = $this->typeParser->use('date');

        $attributes['id'] ??= $this->getId($key);
        $attributes['name'] ??= static::getName($key);
        $attributes['type'] ??= 'date';
        $attributes['value'] ??= $this->getValue($key, $attributes);
        $attributes['required'] ??= $context->isRequired($key);

        $value = $parser->parse($attributes['value']);

        if ($value) {
            $attributes['value'] = $value
                ->toNativeDateTime()
                ->format('Y-m-d');
        } else {
            unset($attributes['value']);
        }

        return static::cleanAttributes($attributes) |> $this->formBuilder->input(
            attributes: ?
        );
    }

    /**
     * Renders a datetime input.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The input attributes.
     * @return string The datetime input HTML.
     */
    public function datetime(string $key, array $attributes = []): string
    {
        $context = $this->getContext();
        $parser = $this->typeParser->use('datetime');

        assert($parser instanceof DateTimeType);

        $attributes['id'] ??= $this->getId($key);
        $attributes['name'] ??= static::getName($key);
        $attributes['type'] ??= 'datetime-local';
        $attributes['value'] ??= $this->getValue($key, $attributes);
        $attributes['required'] ??= $context->isRequired($key);

        $value = $parser->parse($attributes['value']);

        if ($value) {
            $timeZone = $parser->getUserTimeZone();

            if ($timeZone) {
                $value = $value->withTimeZone($timeZone);
            }

            $attributes['value'] = $value
                ->toNativeDateTime()
                ->format('Y-m-d\TH:i');
        } else {
            unset($attributes['value']);
        }

        return static::cleanAttributes($attributes) |> $this->formBuilder->input(
            attributes: ?
        );
    }

    /**
     * Renders an email input.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The input attributes.
     * @return string The email input HTML.
     */
    public function email(string $key, array $attributes = []): string
    {
        $attributes['type'] ??= 'email';

        return $this->text($key, $attributes);
    }

    /**
     * Renders a fieldset close tag.
     *
     * @return string The fieldset close HTML.
     */
    public function fieldsetClose(): string
    {
        return $this->formBuilder->fieldsetClose();
    }

    /**
     * Renders a fieldset open tag.
     *
     * @param array<mixed> $attributes The fieldset attributes.
     * @return string The fieldset open HTML.
     */
    public function fieldsetOpen(array $attributes = []): string
    {
        return $this->formBuilder->fieldsetOpen($attributes);
    }

    /**
     * Renders a file input.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The input attributes.
     * @return string The file input HTML.
     */
    public function file(string $key, array $attributes = []): string
    {
        $attributes['type'] ??= 'file';
        $attributes['value'] = false;

        return $this->text($key, $attributes);
    }

    /**
     * Renders a hidden input.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The input attributes.
     * @return string The hidden input HTML.
     */
    public function hidden(string $key, array $attributes = []): string
    {
        $attributes['type'] ??= 'hidden';
        $attributes['required'] = false;

        return $this->text($key, $attributes);
    }

    /**
     * Renders an image input.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The input attributes.
     * @return string The image input HTML.
     */
    public function image(string $key, array $attributes = []): string
    {
        $attributes['type'] ??= 'image';
        $attributes['value'] = false;

        return $this->text($key, $attributes);
    }

    /**
     * Renders an input element.
     *
     * @param string $key The field key.
     * @param mixed ...$args The input options.
     * @return string The input HTML.
     *
     * @throws InvalidArgumentException If the input type is not valid.
     */
    public function input(string $key, mixed ...$args): string
    {
        $context = $this->getContext();

        $type = $args[0]['type'] ?? $context->getType($key);

        unset($args[0]['type']);

        if (!in_array($type, static::INPUT_TYPES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Input type `%s` is not valid.',
                $type
            ));
        }

        return $this->$type($key, ...$args);
    }

    /**
     * Renders a label element.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The label attributes.
     * @param string|null $text The label text.
     * @param bool $escape Whether to escape the label content.
     * @return string The label HTML.
     */
    public function label(string $key, array $attributes = [], string|null $text = null, bool $escape = true): string
    {
        $attributes['for'] ??= $this->getId($key);
        $text ??= $this->getLabelText($key);

        if ($attributes['for'] === false) {
            unset($attributes['for']);
        }

        return $this->formBuilder->label($text, $attributes, $escape);
    }

    /**
     * Renders a legend element.
     *
     * @param string $content The legend content.
     * @param array<mixed> $attributes The legend attributes.
     * @param bool $escape Whether to escape the legend content.
     * @return string The legend HTML.
     */
    public function legend(string $content = '', array $attributes = [], bool $escape = true): string
    {
        return $this->formBuilder->legend($content, $attributes, $escape);
    }

    /**
     * Renders a month input.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The input attributes.
     * @return string The month input HTML.
     */
    public function month(string $key, array $attributes = []): string
    {
        $attributes['type'] ??= 'month';

        return $this->text($key, $attributes);
    }

    /**
     * Renders a number input.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The input attributes.
     * @return string The number input HTML.
     */
    public function number(string $key, array $attributes = []): string
    {
        $context = $this->getContext();
        $parser = $this->typeParser->use('float');

        $attributes['id'] ??= $this->getId($key);
        $attributes['name'] ??= static::getName($key);
        $attributes['placeholder'] ??= $this->getLabelText($key);
        $attributes['type'] ??= 'number';
        $attributes['value'] ??= $this->getValue($key, $attributes);
        $attributes['min'] ??= $context->getMin($key);
        $attributes['max'] ??= $context->getMax($key);
        $attributes['step'] ??= $context->getStep($key);
        $attributes['required'] ??= $context->isRequired($key);

        if ($attributes['value'] !== false) {
            $attributes['value'] = $parser->parse($attributes['value']);
        }

        return static::cleanAttributes($attributes) |> $this->formBuilder->input(
            attributes: ?
        );
    }

    /**
     * Renders a form open tag.
     *
     * @param object|null $item The context item.
     * @param array<mixed> $attributes The form attributes.
     * @return string The form open HTML.
     *
     * @throws BadMethodCallException If there is an unclosed form.
     * @throws InvalidArgumentException If the context is not valid.
     */
    public function open(object|null $item = null, array $attributes = [], string|null $idPrefix = null): string
    {
        if ($this->context) {
            throw new BadMethodCallException('Unable to open form while existing form context is not closed.');
        }

        if ($item) {
            $contextKey = $item::class;

            while (!isset(static::$contextMap[$contextKey])) {
                $classParents ??= class_parents($item::class);
                $contextKey = array_shift($classParents);

                if (!$contextKey) {
                    throw new InvalidArgumentException(sprintf(
                        'Item class `%s` does not have a mapped context.',
                        $item::class
                    ));
                }
            }

            $contextClass = (string) static::$contextMap[$contextKey];

            if (!is_subclass_of($contextClass, Context::class)) {
                throw new InvalidArgumentException(sprintf(
                    'Context `%s` must extend `%s`.',
                    $contextClass,
                    Context::class
                ));
            }

            $this->context = $this->container->build($contextClass, ['item' => $item]);
        } else {
            $this->context = static::getNullContext();
        }

        $attributes['action'] ??= null;

        if (!$attributes['action']) {
            $attributes['action'] = (string) $this->request->getUri();
        }

        if ($idPrefix) {
            $this->idPrefix = $idPrefix;
        }

        $html = $this->formBuilder->open('', $attributes);

        $csrf = $this->request->getAttribute('csrf');

        if ($csrf) {
            $html .= $this->formBuilder->hidden(null, [
                'name' => $csrf->getField(),
                'value' => $csrf->getFormToken(),
            ]);
        }

        return $html;
    }

    /**
     * Renders a multipart form open tag.
     *
     * @param object|null $item The context item.
     * @param array<mixed> $attributes The form attributes.
     * @return string The form open HTML.
     */
    public function openMultipart(object|null $item = null, array $attributes = [], string|null $idPrefix = null): string
    {
        $attributes['enctype'] = 'multipart/form-data';

        return $this->open($item, $attributes, $idPrefix);
    }

    /**
     * Renders a password input.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The input attributes.
     * @return string The password input HTML.
     */
    public function password(string $key, array $attributes = []): string
    {
        $attributes['type'] ??= 'password';
        $attributes['value'] = false;

        return $this->text($key, $attributes);
    }

    /**
     * Renders a radio input.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The input attributes.
     * @return string The radio input HTML.
     */
    public function radio(string $key, array $attributes = []): string
    {
        $context = $this->getContext();
        $parser = $this->typeParser->use('boolean');

        $attributes['id'] ??= $this->getId($key);
        $attributes['name'] ??= static::getName($key);
        $attributes['type'] ??= 'radio';
        $attributes['value'] ??= false;

        if ($attributes['value'] !== false) {
            $attributes['checked'] ??= $this->getValue($key, $attributes) == $attributes['value'];
        } else {
            $attributes['checked'] = false;
        }

        $attributes['required'] ??= $context->isRequired($key);

        $attributes['checked'] = $parser->parse($attributes['checked']);

        return static::cleanAttributes($attributes) |> $this->formBuilder->input(
            attributes: ?
        );
    }

    /**
     * Renders a range input.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The input attributes.
     * @return string The range input HTML.
     */
    public function range(string $key, array $attributes = []): string
    {
        $attributes['type'] ??= 'range';
        $attributes['required'] = false;

        return $this->text($key, $attributes);
    }

    /**
     * Renders a reset input.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The input attributes.
     * @return string The reset input HTML.
     */
    public function reset(string $key, array $attributes = []): string
    {
        $attributes['type'] ??= 'reset';
        $attributes['required'] = false;

        return $this->text($key, $attributes);
    }

    /**
     * Renders a search input.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The input attributes.
     * @return string The search input HTML.
     */
    public function search(string $key, array $attributes = []): string
    {
        $attributes['type'] ??= 'search';

        return $this->text($key, $attributes);
    }

    /**
     * Renders a select element.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The select attributes.
     * @param array<mixed>|null $options The select options.
     * @param bool $hiddenField Whether to render a hidden field.
     * @return string The select HTML.
     */
    public function select(string $key, array $attributes = [], array|null $options = null, bool $hiddenField = true): string
    {
        $context = $this->getContext();

        $attributes['id'] ??= $this->getId($key);
        $attributes['name'] ??= static::getName($key);
        $attributes['value'] ??= $this->getValue($key, $attributes);
        $attributes['required'] ??= $context->isRequired($key);
        $attributes['multiple'] ??= false;

        $attributes['value'] ??= [];
        $attributes['value'] = array_map(
            EnumHelper::normalizeValue(...),
            (array) $attributes['value']
        );

        $options ??= $this->buildEnumOptions($key);
        $options ??= $context->getOptionValues($key);

        if ($attributes['value'] !== []) {
            $options ??= array_map(
                static fn(mixed $value): array => [
                    'value' => $value,
                    'label' => '',
                ],
                $attributes['value']
            );
        } else {
            $options ??= [];
        }

        $result = '';

        if ($attributes['multiple'] && $hiddenField && $attributes['name'] !== false) {
            $result .= $this->formBuilder->hidden(null, [
                'name' => $attributes['name'],
                'value' => '',
            ]);
        }

        if ($attributes['multiple'] && $attributes['name'] !== false && !str_ends_with($attributes['name'], '[]')) {
            $attributes['name'] .= '[]';
        }

        $result .= static::cleanAttributes($attributes)
            |> $this->formBuilder->select(
                options: $options,
                attributes: ?
            );

        return $result;
    }

    /**
     * Renders a multiple select element.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The select attributes.
     * @param array<mixed> $options The select options.
     * @param bool $hiddenField Whether to render a hidden field.
     * @return string The select HTML.
     */
    public function selectMulti(string $key, array $attributes = [], array|null $options = null, bool $hiddenField = true): string
    {
        $attributes['multiple'] ??= true;

        return $this->select($key, $attributes, $options, $hiddenField);
    }

    /**
     * Renders a submit input.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The input attributes.
     * @return string The submit input HTML.
     */
    public function submit(string $key, array $attributes = []): string
    {
        $attributes['type'] ??= 'submit';
        $attributes['required'] = false;

        return $this->text($key, $attributes);
    }

    /**
     * Renders a telephone input.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The input attributes.
     * @return string The telephone input HTML.
     */
    public function tel(string $key, array $attributes = []): string
    {
        $attributes['type'] ??= 'tel';

        return $this->text($key, $attributes);
    }

    /**
     * Renders a text input.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The input attributes.
     * @return string The text input HTML.
     */
    public function text(string $key, array $attributes = []): string
    {
        $context = $this->getContext();
        $parser = $this->typeParser->use('string');

        $attributes['id'] ??= $this->getId($key);
        $attributes['name'] ??= static::getName($key);
        $attributes['type'] ??= 'text';

        if (in_array($attributes['type'], ['text', 'email', 'search', 'password', 'tel', 'url', 'number'], true)) {
            $attributes['placeholder'] ??= $this->getLabelText($key);
        }

        $attributes['value'] ??= $this->getValue($key, $attributes);
        $attributes['required'] ??= $context->isRequired($key);

        if (in_array($attributes['type'], ['text', 'email', 'search', 'password', 'tel', 'url'], true)) {
            $attributes['maxlength'] ??= $context->getMaxLength($key);
        }

        if ($attributes['value'] !== false) {
            $attributes['value'] = $parser->parse($attributes['value']);
        }

        return static::cleanAttributes($attributes) |> $this->formBuilder->input(
            attributes: ?
        );
    }

    /**
     * Renders a textarea element.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The textarea attributes.
     * @return string The textarea HTML.
     */
    public function textarea(string $key, array $attributes = []): string
    {
        $context = $this->getContext();
        $parser = $this->typeParser->use('string');

        $attributes['id'] ??= $this->getId($key);
        $attributes['name'] ??= static::getName($key);
        $attributes['placeholder'] ??= $this->getLabelText($key);
        $attributes['value'] ??= $this->getValue($key, $attributes);
        $attributes['required'] ??= $context->isRequired($key);
        $attributes['maxlength'] ??= $context->getMaxLength($key);

        if ($attributes['value'] !== false) {
            $attributes['value'] = $parser->parse($attributes['value']);
        }

        return static::cleanAttributes($attributes) |> $this->formBuilder->textarea(
            attributes: ?
        );
    }

    /**
     * Renders a time input.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The input attributes.
     * @return string The time input HTML.
     */
    public function time(string $key, array $attributes = []): string
    {
        $context = $this->getContext();
        $parser = $this->typeParser->use('time');

        $attributes['id'] ??= $this->getId($key);
        $attributes['name'] ??= static::getName($key);
        $attributes['type'] ??= 'time';
        $attributes['value'] ??= $this->getValue($key, $attributes);
        $attributes['required'] ??= $context->isRequired($key);

        $value = $parser->parse($attributes['value']);

        if ($value) {
            $attributes['value'] = $value
                ->toNativeDateTime()
                ->format('H:i');
        } else {
            unset($attributes['value']);
        }

        return static::cleanAttributes($attributes) |> $this->formBuilder->input(
            attributes: ?
        );
    }

    /**
     * Renders a URL input.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The input attributes.
     * @return string The url input HTML.
     */
    public function url(string $key, array $attributes = []): string
    {
        $attributes['type'] ??= 'url';

        return $this->text($key, $attributes);
    }

    /**
     * Renders a week input.
     *
     * @param string $key The field key.
     * @param array<mixed> $attributes The input attributes.
     * @return string The week input HTML.
     */
    public function week(string $key, array $attributes = []): string
    {
        $attributes['type'] ??= 'week';

        return $this->text($key, $attributes);
    }

    /**
     * Builds enum options from the form context.
     *
     * @param string $key The field key.
     * @return array<string, string>|null The options.
     */
    protected function buildEnumOptions(string $key): array|null
    {
        $enumClass = $this->getContext()->getEnumClass($key);

        if (!$enumClass) {
            return null;
        }

        $options = [];

        foreach ($enumClass::cases() as $case) {
            $value = $case instanceof BackedEnum ?
                $case->value :
                $case->name;
            $options[(string) $value] = $case instanceof EnumLabelInterface ?
                $case->label() :
                $this->inflector->humanize($case->name);
        }

        return $options;
    }

    /**
     * Returns the form Context.
     *
     * @return Context The form Context.
     */
    protected function getContext(): Context
    {
        return $this->context ?? static::getNullContext();
    }

    /**
     * Returns the field ID.
     *
     * @param string $key The field key.
     * @return string The field ID.
     */
    protected function getId(string $key): string
    {
        if ($this->idPrefix) {
            $key = $this->idPrefix.'.'.$key;
        }

        return (string) preg_replace('/(?<!\.)[._]([^._]+)/', '-\1', $key);
    }

    /**
     * Returns the field label text.
     *
     * @param string $key The field key.
     * @return string The field label text.
     */
    protected function getLabelText(string $key): string
    {
        $parts = explode('.', $key);
        $field = array_pop($parts);

        $label = $this->lang->get('Form.'.$field);

        if (is_array($label)) {
            $label = null;
        }

        if ($label) {
            return $label;
        }

        return $this->inflector->humanize($field);
    }

    /**
     * Returns the field value.
     *
     * @param string $key The field key.
     * @param array<string, mixed> $options The input options.
     * @return mixed The value.
     */
    protected function getValue(string $key, array $options): mixed
    {
        $context = $this->getContext();
        $options['name'] ??= null;

        $value = null;
        $hasDataValue = false;
        $body = $this->request->getParsedBody();

        if ($options['name'] && is_array($body)) {
            $dataKey = static::getKey($options['name']);

            if (Arr::hasDot($body, $dataKey)) {
                $value = Arr::getDot($body, $dataKey);
                $hasDataValue = true;
            }
        }

        if (!$hasDataValue) {
            $value = $context->getValue($key);
            $value ??= $options['default'] ?? null;
            $value ??= $context->getDefaultValue($key);
        }

        return is_array($value) ?
            array_map(EnumHelper::normalizeValue(...), $value) :
            EnumHelper::normalizeValue($value);
    }

    /**
     * Cleans the input attributes.
     *
     * @param array<mixed> $attributes The input attributes.
     * @return array<string, mixed> The input attributes.
     */
    protected static function cleanAttributes(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if ($value !== false || str_starts_with((string) $key, 'data-')) {
                continue;
            }

            unset($attributes[$key]);
        }

        unset($attributes['default']);

        return $attributes;
    }

    /**
     * Returns the field key.
     *
     * @param string $name The field name.
     * @return string The field key.
     */
    protected static function getKey(string $name): string
    {
        return ((string) preg_replace('/\[(.*?)\]/', '.\1', $name)) |> trim(?, '.');
    }

    /**
     * Returns the field name.
     *
     * @param string $key The field key.
     * @return string The field name.
     */
    protected static function getName(string $key): string
    {
        return (string) preg_replace('/(?<!\.)\.([^.]+)/', '[\1]', $key);
    }

    /**
     * Returns the NullContext.
     *
     * @return Context The NullContext.
     */
    protected static function getNullContext(): Context
    {
        return static::$nullContext ??= new NullContext();
    }
}
